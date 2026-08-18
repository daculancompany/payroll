<?php
ini_set('serialize_precision', '-1');
require_once __DIR__ . '/includes/session_bootstrap.php';

// Error reporting is centralised in db_connect.php (APP_ENV switch).

// Composer autoload (PhpSpreadsheet). Load defensively so a missing/partial
// vendor/ folder on the host can't 500 the whole app — only the Excel
// import/export features will be unavailable until vendor/ is restored.
if (file_exists(__DIR__ . '/vendor/composer/autoload_real.php')) {
    require __DIR__ . '/vendor/autoload.php';   // Keep at the top
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Action
{
    private $db;

    /** work_schedules id → description, memoised for dutyCellLabel(). */
    private $dutyShiftNames = null;

    public function __construct()
    {
        ob_start();

        include 'db_connect.php';

        $this->db = $conn;
        // Publish the handle as the global the db_connect.php helpers read
        // (dtr_late_rules, dtr_early_grace_hours, dept-scope). Included from
        // inside a method, $conn is a local here and $GLOBALS['conn'] never
        // gets set on the ajax surface — so every pay_settings-backed rule
        // silently fell back to its constant default for scans and recomputes
        // (late brackets read as exact minutes) while the pages showed the
        // configured values.
        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
            $GLOBALS['conn'] = $conn;
        }
    }

    function __destruct()
    {
        $this->db->close();

        ob_end_flush();
    }

    function save_cluster()
    {
        extract($_POST);
        $data = " cluster='$cluster' ";
        if (empty($id)) {
            $this->db->query("INSERT INTO clusters set " . $data);
            return 1;
        } else {
            $this->db->query("UPDATE clusters set " . $data . " where id=" . $id);
            return 2;
        }
    }


    // Unified login: tries ADMIN (users) first, then EMPLOYEE (employee_portal_accounts).
    // Rate-limited per username+IP. Returns a 'redirect' target on success.
    function login()
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $identity = 'login:' . strtolower($username) . '|' . $ip;
        $MAX = 5;
        $LOCK = 15; // attempts / minutes

        if ($username === '' || $password === '') {
            return ['result' => false, 'message' => 'Please enter your email and password.'];
        }

        // Optional tables — if they don't exist (e.g. fresh deploy) we just skip
        // that feature instead of crashing.
        $has_rl   = $this->tableExists('login_attempts');
        $has_acct = $this->tableExists('employee_portal_accounts');

        // Lockout check (only if the table exists)
        if ($has_rl && ($rl = $this->db->prepare("SELECT (locked_until > NOW()) AS locked, TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS secs FROM login_attempts WHERE identifier = ?"))) {
            $rl->bind_param('s', $identity);
            $rl->execute();
            $att = $rl->get_result()->fetch_assoc();
            if ($att && $att['locked']) {
                return ['result' => false, 'message' => 'Too many failed attempts. Try again in ' . ceil($att['secs'] / 60) . ' minute(s).'];
            }
        }

        // ── 1) ADMIN / STAFF (users table) ──
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? AND status = 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $ures = $stmt->get_result();
        if ($ures->num_rows === 1) {
            $row = $ures->fetch_assoc();
            // Timekeepers (role 5) are allowed in — index.php + the navbar cut
            // them down to the attendance report, the employee list and the
            // fingerprints-only employee detail page.
            if (!empty($row['password']) && password_verify($password, $row['password'])) {
                $this->clearLoginAttempts($identity);
                @session_regenerate_id(true);
                // clear any employee session, then set admin session
                foreach (['emp_is_login', 'emp_id', 'emp_no', 'emp_name', 'emp_dept', 'emp_position', 'emp_bday'] as $k) unset($_SESSION[$k]);
                foreach ($row as $key => $value) {
                    if ($key != 'password' && !is_numeric($key)) $_SESSION['login_' . $key] = $value;
                }
                $_SESSION['is_login'] = true;
                return ['result' => true, 'message' => 'login successful', 'redirect' => $this->roleLanding((int)$row['role'])];
            }
        }

        // ── 2) EMPLOYEE (employee_portal_accounts) — sign in with employee_no OR account email ──
        // The offline payroll machine (APP_ROLE=local) is ADMIN ONLY: skip the
        // employee branch entirely so employee credentials can never open a
        // session on the box that holds the salary data.
        if (function_exists('app_is_local') && app_is_local()) {
            $up = $this->db->prepare("
                INSERT INTO login_attempts (identifier, ip, attempts, locked_until) VALUES (?, ?, 1, NULL)
                ON DUPLICATE KEY UPDATE attempts = attempts + 1,
                    locked_until = IF(attempts + 1 >= $MAX, DATE_ADD(NOW(), INTERVAL $LOCK MINUTE), locked_until)
            ");
            $up->bind_param('ss', $identity, $ip);
            $up->execute();
            return ['result' => false, 'message' => 'Invalid username or password.'];
        }
        $acct_join = $has_acct ? "LEFT JOIN employee_portal_accounts a ON a.employee_id = e.id" : "";
        $acct_cols = $has_acct ? "a.password AS acct_pass, a.is_active AS acct_active" : "NULL AS acct_pass, 1 AS acct_active";
        // Employees may sign in with their employee_no OR the email stored as
        // the portal account username. Fall back gracefully with no acct table.
        $id_where  = $has_acct ? "(e.employee_no = ? OR a.username = ?)" : "e.employee_no = ?";
        $estmt = $this->db->prepare("
            SELECT e.*, COALESCE(d.name,'—') AS dept_name, COALESCE(p.name,'—') AS position_name,
                   $acct_cols
            FROM employee e
            LEFT JOIN department d ON e.department_id = d.id
            LEFT JOIN position   p ON e.position_id  = p.id
            $acct_join
            WHERE $id_where AND e.status = 1 LIMIT 1
        ");
        $emp = false;
        if ($estmt) {
            if ($has_acct) $estmt->bind_param('ss', $username, $username);
            else           $estmt->bind_param('s', $username);
            $estmt->execute();
            $emp = $estmt->get_result()->fetch_assoc();
        }
        $emp_ok = false;
        if ($emp) {
            if (!empty($emp['acct_pass'])) {
                $emp_ok = ((int)$emp['acct_active'] === 1) && password_verify($password, $emp['acct_pass']);
            } else {
                $def = $emp['bday'] ? date('mdY', strtotime($emp['bday'])) : $emp['employee_no'];
                $emp_ok = hash_equals($def, $password) || hash_equals($emp['employee_no'], $password);
            }
        }
        if ($emp_ok) {
            $this->clearLoginAttempts($identity);
            $this->db->query("UPDATE employee_portal_accounts SET last_login = NOW() WHERE employee_id = " . (int)$emp['id']);
            @session_regenerate_id(true);
            // clear any admin session, then set employee session
            foreach ($_SESSION as $k => $v) {
                if (strpos($k, 'login_') === 0) unset($_SESSION[$k]);
            }
            unset($_SESSION['is_login']);
            $_SESSION['emp_is_login'] = true;
            $_SESSION['emp_id']       = $emp['id'];
            $_SESSION['emp_no']       = $emp['employee_no'];
            $_SESSION['emp_name']     = $emp['firstname'] . ' ' . $emp['lastname'];
            $_SESSION['emp_dept']     = $emp['dept_name'];
            $_SESSION['emp_position'] = $emp['position_name'];
            $_SESSION['emp_bday']     = $emp['bday'];
            return ['result' => true, 'message' => 'login successful', 'redirect' => 'employee-portal.php'];
        }

        // ── 3) Failure → record attempt (generic message, no info leak) ──
        $up = $this->db->prepare("
            INSERT INTO login_attempts (identifier, ip, attempts, locked_until) VALUES (?, ?, 1, NULL)
            ON DUPLICATE KEY UPDATE attempts = attempts + 1,
                locked_until = IF(attempts + 1 >= $MAX, DATE_ADD(NOW(), INTERVAL $LOCK MINUTE), locked_until)
        ");
        $up->bind_param('ss', $identity, $ip);
        $up->execute();

        return ['result' => false, 'message' => 'Invalid username or password.'];
    }

    // Post-login landing page per staff role. Falls back to the dashboard for
    // any role without a dedicated page. Employees are handled separately and
    // always land on employee-portal.php.
    private function roleLanding($role)
    {
        $map = [
            1 => 'index.php?page=home',        // Administrator
            2 => 'index.php?page=home',        // Staff
            3 => 'index.php?page=reports',     // Auditor
            4 => 'index.php?page=payroll-list', // Payroll Clerk
            5 => 'index.php?page=attendance-summary', // Timekeeper
            6 => 'index.php?page=daily-board', // PIC
            7 => 'index.php?page=reports',     // Auditor
            8 => 'index.php?page=leaves',      // Department Head
            9 => 'index.php?page=leaves',      // HR
            10 => 'index.php?page=leaves',     // Supervisor
        ];
        return $map[(int)$role] ?? 'index.php?page=home';
    }

    private function clearLoginAttempts($identity)
    {
        $d = $this->db->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $d->bind_param('s', $identity);
        $d->execute();
    }

    // Returns true if the given table exists in the current database.
    private function tableExists($table)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count > 0;
    }

    /**
     * True only for an Administrator (role 1). Portal credentials on the
     * employee form are admin-only — Staff/Auditor may still save the record,
     * they just can't set or change the login.
     */
    private function is_admin_session()
    {
        return (int) ($_SESSION['login_role'] ?? 0) === 1;
    }

    /**
     * Give a newly created employee a portal login.
     *
     * Username is the supplied email when it is a real one, otherwise
     * firstname.lastname@<default domain>. employee_portal_accounts.username is
     * UNIQUE, so a numeric suffix is added until the candidate is free —
     * duplicate emails across staff are common and must not abort the insert.
     *
     * $password is the admin-typed password; blank falls back to
     * PORTAL_DEFAULT_PASSWORD with must_change = 1 so the employee is asked to
     * pick their own. A password the admin chose deliberately is left as-is.
     *
     * No-op when the employee already has an account, which keeps re-imports
     * from resetting a password the employee has already changed.
     */
    private function ensure_portal_account($employee_id, $firstname, $lastname, $email = '', $password = '')
    {
        if (!$this->tableExists('employee_portal_accounts')) return null;
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0) return null;

        $chk = $this->db->query("SELECT id FROM employee_portal_accounts WHERE employee_id = $employee_id LIMIT 1");
        if ($chk && $chk->num_rows > 0) return null;

        $email = strtolower(trim((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $slug = function ($s) {
                return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $s)));
            };
            $base = $slug($firstname) . '.' . $slug($lastname);
            if ($base === '' || $base === '.') $base = 'user' . $employee_id;
            $email = $base . '@' . PORTAL_DEFAULT_EMAIL_DOMAIN;
        }

        $parts  = array_pad(explode('@', $email, 2), 2, PORTAL_DEFAULT_EMAIL_DOMAIN);
        $local  = $parts[0];
        $domain = $parts[1];

        $find = $this->db->prepare("SELECT id FROM employee_portal_accounts WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $candidate = $email;
        $n = 1;
        while (true) {
            $find->bind_param('s', $candidate);
            $find->execute();
            $find->store_result();
            $taken = $find->num_rows > 0;
            $find->free_result();
            if (!$taken) break;
            $n++;
            $candidate = $local . $n . '@' . $domain;
        }

        $password    = (string) $password;
        $must_change = $password === '' ? 1 : 0;
        $hash = password_hash($password === '' ? PORTAL_DEFAULT_PASSWORD : $password, PASSWORD_BCRYPT);
        $ins  = $this->db->prepare("INSERT INTO employee_portal_accounts
            (employee_id, username, password, is_active, must_change) VALUES (?, ?, ?, 1, ?)");
        $ins->bind_param('issi', $employee_id, $candidate, $hash, $must_change);
        $ins->execute();

        return $candidate;
    }

    /**
     * Admin-only edit of an existing employee's portal login.
     *
     * Blank email keeps the current username, blank password keeps the current
     * password — so an admin can change one without touching the other. Unlike
     * ensure_portal_account() a taken email is an ERROR here rather than being
     * silently suffixed: the admin typed a specific address and has to be told
     * it belongs to someone else.
     *
     * Returns an 'error:…' string when the input is rejected, null on success.
     */
    private function update_portal_account($employee_id, $email, $password)
    {
        if (!$this->tableExists('employee_portal_accounts')) return null;
        $employee_id = (int) $employee_id;
        $email       = strtolower(trim((string) $email));
        $password    = (string) $password;
        if ($employee_id <= 0 || ($email === '' && $password === '')) return null;

        $cur = $this->db->query("SELECT id, username FROM employee_portal_accounts WHERE employee_id = $employee_id LIMIT 1");
        $row = $cur ? $cur->fetch_assoc() : null;

        // No account yet (imported or pre-portal employee) — create one now.
        if (!$row) {
            $e = $this->db->query("SELECT firstname, lastname FROM employee WHERE id = $employee_id LIMIT 1");
            $emp = $e ? $e->fetch_assoc() : ['firstname' => '', 'lastname' => ''];
            $this->ensure_portal_account($employee_id, $emp['firstname'], $emp['lastname'], $email, $password);
            return null;
        }

        if ($email !== '') {
            $dup = $this->db->prepare("SELECT id FROM employee_portal_accounts WHERE LOWER(username) = LOWER(?) AND employee_id <> ? LIMIT 1");
            $dup->bind_param('si', $email, $employee_id);
            $dup->execute();
            $dup->store_result();
            $taken = $dup->num_rows > 0;
            $dup->free_result();
            if ($taken) return 'error:That login email is already used by another employee.';

            $u = $this->db->prepare("UPDATE employee_portal_accounts SET username = ? WHERE employee_id = ?");
            $u->bind_param('si', $email, $employee_id);
            $u->execute();
        }

        if ($password !== '') {
            // Set by an admin on purpose, so it is not flagged for a forced change.
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $p = $this->db->prepare("UPDATE employee_portal_accounts SET password = ?, must_change = 0, is_active = 1 WHERE employee_id = ?");
            $p->bind_param('si', $hash, $employee_id);
            $p->execute();
        }

        return null;
    }

    // Returns true if the given column exists on the given table.
    private function columnExists($table, $column)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count > 0;
    }

    /* login2() and signup() removed — see the note in ajax.php.
     *
     * Both concatenated $_POST directly into SQL, so `email=' OR 1=1 -- `
     * matched the first users row and was written into $_SESSION as a full
     * admin session without any password. Both also hashed with unsalted
     * md5(), bypassed the login_attempts rate limiting, and (via a typo that
     * filtered 'passwors' instead of 'password') copied the stored password
     * hash into the session. Neither had any caller. Use login() to sign in
     * and save_user() to create accounts. */

    /** Clear the session and return to the sign-in screen. */
    private function endSession($redirect)
    {
        // Empty the array first: session_destroy() drops the server-side store
        // but leaves $_SESSION populated for the rest of this request.
        $_SESSION = [];
        session_destroy();

        header('location:' . $redirect);
    }

    function logout()
    {
        $this->endSession('login.php');
    }

    function logout2()
    {
        // Was '../index.php', which resolves above the app directory and lands
        // on the XAMPP dashboard / a 404 instead of this app's own login page.
        $this->endSession('login.php');
    }



    function calculate_payrollOld()
    {
        extract($_POST);
        $this->db->query("DELETE FROM payroll_items where payroll_id=" . $id);
        $pay = $this->db->query("SELECT * FROM payroll where id = " . $id)->fetch_array();
        $employee = $this->db->query("SELECT * FROM employee WHERE status = 1 ");
        $calc_days = abs(strtotime($pay['date_to'] . " 23:59:59")) - strtotime($pay['date_from'] . " 00:00:00 -1 day");
        $calc_days = floor($calc_days / (60 * 60 * 24));
        ($att = $this->db->query("SELECT * FROM attendance where date(datetime_log) between '" . $pay['date_from'] . "' and '" . $pay['date_to'] . "' order by UNIX_TIMESTAMP(datetime_log) asc  ")) or die(mysqli_error());
        while ($row = $att->fetch_array()) {
            $date = date("Y-m-d", strtotime($row['datetime_log']));
            if ($row['log_type'] == 1) {
                if (!isset($attendance[$row['employee_id'] . "_" . $date]['log'][$row['log_type']])) {
                    $attendance[$row['employee_id'] . "_" . $date]['log'][$row['log_type']] = $row['datetime_log'];
                }
            } else {
                $attendance[$row['employee_id'] . "_" . $date]['log'][$row['log_type']] = $row['datetime_log'];
            }
        }
        $deductions = $this->db->query("SELECT * FROM employee_deductions where (`type` = '" . $pay['type'] . "' or (date(effective_date) between '" . $pay['date_from'] . "' and '" . $pay['date_from'] . "' ) ) ");
        $allowances = $this->db->query("SELECT * FROM employee_allowances where (`type` = '" . $pay['type'] . "' or (date(effective_date) between '" . $pay['date_from'] . "' and '" . $pay['date_from'] . "' ) ) ");
        while ($row = $deductions->fetch_assoc()) {
            $ded[$row['employee_id']][] = ['did' => $row['deduction_id'], "amount" => $row['amount']];
        }
        while ($row = $allowances->fetch_assoc()) {
            $allow[$row['employee_id']][] = ['aid' => $row['allowance_id'], "amount" => $row['amount']];
        }

        while ($row = $employee->fetch_assoc()) {
            $am_in = $row['time_in'];
            $am_out = $row['time_out'];
            $salary = $row['salary'];
            $time_in = $row['time_in'];
            $time_out = $row['time_out'];
            $daily_hours_worked = abs(strtotime($time_out) - strtotime($time_in)) / 3600 - 1;
            $min = $salary / $daily_hours_worked / 60;
            $daily_hours_worked_min = $daily_hours_worked * 60;
            $absent = 0;
            $undertime = 0;
            $late = 0;
            $dp = 22 / $pay['type'];
            $present = 0;
            $net = 0;
            $allow_amount = 0;
            $ded_amount = 0;
            $contribute_amount = 0;
            $time_logs = 0;

            for ($i = 0; $i < $calc_days; $i++) {
                $dd = date("Y-m-d", strtotime($pay['date_from'] . " +" . $i . " days"));
                if (isset($attendance[$row['id'] . "_" . $dd]['log'])) {
                    $count = count($attendance[$row['id'] . "_" . $dd]['log']);
                }
                if (isset($attendance[$row['id'] . "_" . $dd]['log'][1]) && isset($attendance[$row['id'] . "_" . $dd]['log'][4])) {
                    $attendance_morning = strtotime($attendance[$row['id'] . "_" . $dd]['log'][1]);
                    $attendance_morning = date('H:i', $attendance_morning);
                    $attendance_afternoon = strtotime($attendance[$row['id'] . "_" . $dd]['log'][4]);
                    $attendance_afternoon = date('H:i', $attendance_afternoon);

                    $hours_worked = abs(strtotime($attendance_afternoon) - strtotime($attendance_morning)) / 3600 - 1;

                    $undertime_in_minutes = 0;

                    if (floatval($daily_hours_worked) > floatval($hours_worked)) {
                        //$daily_hours_worked_min = $daily_hours_worked * 60;

                        $hours_worked_min = $hours_worked * 60;

                        $undertime_in_minutes = $daily_hours_worked_min - $hours_worked_min;
                    }

                    $late_in_minutes = 0;

                    if (strtotime($am_in) < strtotime($attendance_morning)) {
                        $late_in_minutes = strtotime($attendance_morning) - strtotime($am_in);

                        $late_in_minutes = $late_in_minutes / 60;
                    }
                    $att_mn = abs(strtotime($attendance[$row['id'] . "_" . $dd]['log'][4])) - strtotime($attendance[$row['id'] . "_" . $dd]['log'][1]);
                    $att_mn = floor($att_mn / 60);
                    if ($att_mn > $daily_hours_worked_min) {
                        $att_mn = $daily_hours_worked_min;
                    }
                    $net += $att_mn * $min;
                    $late += $min * $late_in_minutes;
                    $undertime += $min * $undertime_in_minutes;
                    $present += 1;
                }
            }

            $ded_arr = [];
            $all_arr = [];
            if (isset($allow[$row['id']])) {
                foreach ($allow[$row['id']] as $arow) {
                    $all_arr[] = $arow;
                    $net += $arow['amount'];
                    $allow_amount += $arow['amount'];
                }
            }

            if (isset($ded[$row['id']])) {
                foreach ($ded[$row['id']] as $drow) {
                    $ded_arr[] = $drow;
                    $net -= $drow['amount'];
                    $ded_amount += $drow['amount'];
                }
            }

            $contributionList = [];
            $contributions = $this->db->query("SELECT * FROM employee_contributions WHERE employee_id='" . $row['id'] . "'  AND payroll_type='" . $pay['type'] . "'  ");
            while ($row_cont = $contributions->fetch_assoc()) {
                $contributionList[$row_cont['employee_id']][] = ['cid' => $row_cont['contribution_id'], "amount" => $row_cont['amount']];
                $net -= $row_cont['amount'];
                $contribute_amount += $row_cont['amount'];
            }
            $timeLogsList = [];
            $timelogquery = $this->db->query("SELECT * FROM time_logs WHERE employee_id='" . $row['id'] . "'  ");
            while ($row_logs = $timelogquery->fetch_assoc()) {
                $time_log_min = $row_logs['total_hours'] * 60 * $min;
                $timeLogsList[$row_logs['employee_id']][] = ['tid' => $row_logs['id'], "total_hours" => $row_logs['total_hours'], "amount" => $time_log_min, 'rate' => $min];
                $net += $time_log_min;
                $time_logs += $time_log_min;
            }
            $net = $net - $late;
            $absent = $dp - $present;
            $data = " payroll_id = '" . $pay['id'] . "' ";
            $data .= ", employee_id = '" . $row['id'] . "' ";
            $data .= ", absent = '$absent' ";
            $data .= ", present = '$present' ";
            $data .= ", late = '$late' ";
            $data .= ", under_time = '$undertime' ";
            $data .= ", salary = '$salary' ";
            $data .= ", allowance_amount = '$allow_amount' ";
            $data .= ", contribute_amount = '$contribute_amount' ";
            $data .= ", deduction_amount = '$ded_amount' ";
            $data .= ", time_log_amount = '$time_logs' ";
            $data .= ", time_logs = '" . json_encode($timeLogsList) . "' ";
            $data .= ", allowances = '" . json_encode($all_arr) . "' ";
            $data .= ", deductions = '" . json_encode($ded_arr) . "' ";
            $data .= ", contributions = '" . json_encode($contributionList) . "' ";
            $data .= ", net = '$net' "; // var_dump($data);
            $save[] = $this->db->query("INSERT INTO payroll_items set " . $data);
        }

        if (isset($save)) {
            $this->db->query("UPDATE payroll set status = 1 where id = " . $pay['id']);

            return 1;
        }
    }


    /**
     * Validate an uploaded image and store it under $destDir with a safe,
     * server-generated name. Returns the stored basename, or false if the file
     * is not a real image.
     *
     * The name is NEVER derived from $_FILES['name']: the previous code did
     * exactly that, so uploading "shell.php" wrote an executable PHP file into
     * a web-served directory — remote code execution. The extension here comes
     * from the image type the server itself detected, so a disguised payload
     * cannot pick its own.
     */
    private function storeImageUpload(array $file, $destDir)
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        // Guards against a caller faking $_FILES to point at an arbitrary path.
        if (!is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        // Decide the type from the file's own contents, not its filename.
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return false;
        }
        $allowed = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];
        if (!isset($allowed[$info[2]])) {
            return false;
        }

        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            return false;
        }

        $basename = uniqid('img_', true) . '.' . $allowed[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], rtrim($destDir, '/') . '/' . $basename)) {
            return false;
        }
        return $basename;
    }

    function save_settings()
    {
        // ── Read inputs explicitly (no extract() — avoids variable injection) ──
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $about   = (string) ($_POST['about'] ?? '');

        $cover_img = null;
        if (!empty($_FILES['img']['tmp_name'])) {
            $cover_img = $this->storeImageUpload($_FILES['img'], 'assets/img/');
            if ($cover_img === false) {
                return ['result' => false, 'message' => 'The cover image must be a JPG, PNG, GIF or WebP image.'];
            }
        }

        // Parameterised: $name/$email/$contact/$about are free text straight
        // from the form, and were previously concatenated into the statement.
        $has_row = (int) $this->db->query("SELECT COUNT(*) AS c FROM system_settings")->fetch_assoc()['c'] > 0;

        if ($has_row) {
            $sql = "UPDATE system_settings SET name = ?, email = ?, contact = ?, about_content = ?"
                 . ($cover_img !== null ? ", cover_img = ?" : "");
        } else {
            $sql = $cover_img !== null
                 ? "INSERT INTO system_settings (name, email, contact, about_content, cover_img) VALUES (?, ?, ?, ?, ?)"
                 : "INSERT INTO system_settings (name, email, contact, about_content) VALUES (?, ?, ?, ?)";
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['result' => false, 'message' => 'Could not save settings.'];
        }
        if ($cover_img !== null) {
            $stmt->bind_param('sssss', $name, $email, $contact, $about, $cover_img);
        } else {
            $stmt->bind_param('ssss', $name, $email, $contact, $about);
        }
        $save = $stmt->execute();
        $stmt->close();

        if ($save) {
            $query = $this->db->query("SELECT * FROM system_settings limit 1")->fetch_array();
            foreach ($query as $key => $value) {
                if (!is_numeric($key)) {
                    $_SESSION['setting_' . $key] = $value;
                }
            }
            return 1;
        }
    }

    function save_employee()
    {
        // ── Read inputs explicitly (no extract() — avoids variable injection) ──
        $id               = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : '';
        $firstname        = trim($_POST['firstname'] ?? '');
        $middlename       = trim($_POST['middlename'] ?? '');
        $lastname         = trim($_POST['lastname'] ?? '');
        $ext              = trim($_POST['ext'] ?? '');
        $position_id      = (int)($_POST['position_id'] ?? 0);
        $clasification_id = (int)($_POST['clasification_id'] ?? 1);
        $salary           = (float)($_POST['salary'] ?? 0);
        $basic_pay        = (float)($_POST['basic_pay'] ?? 0);
        $ot_rate          = (float)($_POST['ot_rate'] ?? 0);
        $sss_fund         = (float)($_POST['sss_fund'] ?? 0);
        $allowance_rate   = (float)($_POST['allowance_rate'] ?? 0);
        $bday             = trim($_POST['bday'] ?? '');
        $employee_code    = trim($_POST['employee_code'] ?? '');
        $rate_type        = in_array($_POST['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $_POST['rate_type'] : 'daily';
        $payroll_type     = 1;
        // Bank / payout details (both optional).
        $bank_id          = !empty($_POST['bank_id']) ? (int) $_POST['bank_id'] : null;
        $bank_account_no  = trim($_POST['bank_account_no'] ?? '');
        if ($bank_account_no !== '' && !preg_match('/^[A-Za-z0-9 \-]{1,50}$/', $bank_account_no)) {
            return 'error:Account number may only contain letters, numbers, spaces and dashes.';
        }
        if ($bank_account_no === '') $bank_account_no = null;

        $status         = isset($_POST['status']) ? 1 : 0;
        $isAutoDeduct   = isset($_POST['isAutoDeduct']) ? 1 : 0;
        // Weekly payroll was removed — everyone is semi-monthly. The column is
        // kept (nothing reads it any more) so existing rows stay loadable; new
        // and edited employees settle to 0.
        $weekly_payroll = 0;

        // ── Server-side validation ──
        if ($firstname === '' || $lastname === '')          return 'error:First and last name are required.';
        if (mb_strlen($firstname) > 50 || mb_strlen($lastname) > 50) return 'error:Name is too long (max 50 characters).';
        if ($position_id <= 0)                              return 'error:Please select a valid position.';
        if ($clasification_id <= 0)                         return 'error:Please select a valid classification.';
        if ($salary < 0 || $basic_pay < 0 || $ot_rate < 0 || $allowance_rate < 0 || $sss_fund < 0)
            return 'error:Pay/rate values cannot be negative.';
        if ($basic_pay > 100000000 || $salary > 100000000) return 'error:Pay value is unrealistically large.';
        if ($bday !== '' && strtotime($bday) === false)     return 'error:Birthday is not a valid date.';

        // ── Portal login (email + password) — ADMINISTRATOR ONLY ──
        // Every other role that can open this form saves the employee record
        // without ever touching the login, whatever they post.
        $portal_email    = '';
        $portal_password = '';
        if ($this->is_admin_session()) {
            $portal_email    = strtolower(trim($_POST['email'] ?? ''));
            $portal_password = (string) ($_POST['portal_password'] ?? '');
            if ($portal_email !== '' && !filter_var($portal_email, FILTER_VALIDATE_EMAIL))
                return 'error:Login email is not a valid email address.';
            if (mb_strlen($portal_email) > 100)
                return 'error:Login email is too long (max 100 characters).';
            if ($portal_password !== '' && strlen($portal_password) < 6)
                return 'error:Login password must be at least 6 characters.';
        }

        // Calculate deductions. Weekly payroll was removed — every employee is
        // semi-monthly, so the monthly SSS/PhilHealth tables always apply.
        $sss = $this->getSSSMonthlyDeduction($basic_pay);
        $phic = $this->calculatePhilHealth($basic_pay);
        $hdmf = 0; // Default value

        // Start transaction
        $this->db->begin_transaction();

        try {
            if (empty($id)) {
                $employee_code = mt_rand(100000000000, 999999999999);
                // Generate unique employee number
                do {
                    $e_num = date('Y') . '-' . mt_rand(1, 99999);

                    // FIXED: Use prepared statement for checking employee number
                    $stmt = $this->db->prepare("SELECT COUNT(*) FROM employee WHERE employee_no = ?");
                    $stmt->bind_param("s", $e_num);
                    $stmt->execute();
                    $stmt->bind_result($chk);
                    $stmt->fetch();
                    $stmt->close();
                } while ($chk > 0);

                // Insert new employee
                $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
                // Ward/section. NULL is a valid answer — the employee then falls
                // back to department-level scoping, which is what everyone did
                // before areas existed.
                $area_id = $this->normalizeAreaId($_POST['area_id'] ?? null, $department_id);
                $query = "INSERT INTO employee
                (employee_no, employee_code, firstname, middlename, lastname, position_id, department_id, area_id, salary, basic_pay, rate_type, status, ot_rate, isAutoDeduct, weekly_payroll, clasification_id, sss_fund, allowance_rate, bday, ext, bank_id, bank_account_no)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param("ssssssssssssssssssssss", $e_num, $employee_code, $firstname, $middlename, $lastname, $position_id, $department_id, $area_id, $salary, $basic_pay, $rate_type, $status, $ot_rate, $isAutoDeduct, $weekly_payroll, $clasification_id, $sss_fund, $allowance_rate, $bday, $ext, $bank_id, $bank_account_no);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $employee_id = $this->db->insert_id; // Get newly inserted employee ID
                } else {
                    throw new Exception("Failed to insert employee.");
                }

                // Default work schedule (DTR_DEFAULT_SHIFT / DTR_DEFAULT_REST_DAYS,
                // db_connect.php) so DTR hour/late computations work from day one.
                $defSched = $this->db->query("SELECT id FROM work_schedules
                    WHERE LOWER(description) = LOWER('" . $this->db->real_escape_string(DTR_DEFAULT_SHIFT) . "')
                      AND status = 1 LIMIT 1")->fetch_assoc();
                if ($defSched) {
                    $ds = $this->db->prepare("INSERT INTO employee_schedules
                        (employee_id, schedule_id, effective_from, rest_days, notes)
                        VALUES (?, ?, CURDATE(), ?, 'Default shift (auto-assigned)')");
                    $dsId = (int)$defSched['id'];
                    $dsRd = DTR_DEFAULT_REST_DAYS;
                    $ds->bind_param('iis', $employee_id, $dsId, $dsRd);
                    $ds->execute();
                }

                // Insert contributions for SSS, PHIC, HDMF
                $contributions = [
                    ['id' => 1, 'amount' => $sss],
                    ['id' => 2, 'amount' => $phic],
                    ['id' => 3, 'amount' => $hdmf]
                ];

                $query = "INSERT INTO employee_contributions (employee_id, contribution_id, amount, payroll_type) VALUES (?, ?, ?, ?)";

                $stmt = $this->db->prepare($query);

                foreach ($contributions as $contribution) {

                    $stmt->bind_param("ssss", $employee_id, $contribution['id'], $contribution['amount'], $payroll_type);
                    $payroll_type = 1;
                    $stmt->execute();
                    if ($stmt->affected_rows <= 0) {
                        throw new Exception("Failed to insert contribution.");
                    }
                }

                // Every new employee gets a portal login straight away. An admin
                // may set the email/password here; anything left blank falls back
                // to a generated address and the default password (must_change = 1).
                $this->ensure_portal_account($employee_id, $firstname, $lastname, $portal_email, $portal_password);

                $this->db->commit();
                return $employee_id;
            } else {
                // Update existing employee
                $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
                $area_id = $this->normalizeAreaId($_POST['area_id'] ?? null, $department_id);
                $query = "UPDATE employee SET
                employee_code=COALESCE(NULLIF(?, ''), employee_code), firstname=?, middlename=?, lastname=?, position_id=?, department_id=?, area_id=?, salary=?, basic_pay=?, rate_type=?, status=?, ot_rate=?, isAutoDeduct=?, weekly_payroll=?, clasification_id=?, sss_fund=?, allowance_rate=?, bday=?, ext=?, bank_id=?, bank_account_no=?
                WHERE id=?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param("ssssssssssssssssssssss", $employee_code, $firstname, $middlename, $lastname, $position_id, $department_id, $area_id, $salary, $basic_pay, $rate_type, $status, $ot_rate, $isAutoDeduct, $weekly_payroll, $clasification_id, $sss_fund, $allowance_rate, $bday, $ext, $bank_id, $bank_account_no, $id);
                $stmt->execute();

                // Portal login — only an admin gets this far with values set.
                // A rejected email (already taken) rolls the whole edit back so
                // the form can be corrected and resubmitted as one change.
                $acct_err = $this->update_portal_account($id, $portal_email, $portal_password);
                if ($acct_err !== null) {
                    $this->db->rollback();
                    return $acct_err;
                }

                $this->db->commit();
                return 'updated';
            }
        } catch (Exception $e) {
            $this->db->rollback(); // Rollback transaction on error
            error_log("Error in save_employee(): " . $e->getMessage()); // Log error
            return 0; // Error occurred
        }
    }





    function save_employee_contribution()
    {

        $type = '';
        $$type = $_POST['type'] ?? '';
        $value = $_POST['value'];
        $id = $_POST['id'];
        // Sanitize inputs
        $id = intval($id);
        $type2 =  $$type;
        $data = "$type2='$value' ";
        $save = $this->db->query("UPDATE employee set " . $data . " where id=" . $id);
        if ($save) {
            return 1;
        }
    }

    function delete_employee()
    {
        extract($_POST);
        $delete = $this->db->query("DELETE FROM employee where id = " . (int)$id);
        if ($delete) {
            return 1;
        }
    }

    function save_branch()
    {
        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $branch_code = $this->db->real_escape_string(trim($_POST['branch_code'] ?? ''));
        $branch_name = $this->db->real_escape_string(trim($_POST['branch_name'] ?? ''));
        $address     = $this->db->real_escape_string(trim($_POST['address'] ?? ''));
        $city        = $this->db->real_escape_string(trim($_POST['city'] ?? ''));
        $phone       = $this->db->real_escape_string(trim($_POST['phone'] ?? ''));
        $email       = $this->db->real_escape_string(trim($_POST['email'] ?? ''));
        $status      = (int)($_POST['status'] ?? 1);

        if (empty($branch_code) || empty($branch_name)) {
            return json_encode(['result' => false, 'message' => 'Branch code and name are required.']);
        }

        if ($id === 0) {
            $save = $this->db->query("INSERT INTO branches (branch_code, branch_name, address, city, phone, email, status) VALUES ('$branch_code','$branch_name','$address','$city','$phone','$email',$status)");
        } else {
            $save = $this->db->query("UPDATE branches SET branch_code='$branch_code', branch_name='$branch_name', address='$address', city='$city', phone='$phone', email='$email', status=$status WHERE id=$id");
        }

        if ($save) {
            return json_encode(['result' => true]);
        }
        return json_encode(['result' => false, 'message' => $this->db->error]);
    }

    function delete_branch()
    {
        $id = (int)($_POST['id'] ?? 0);
        $this->db->query("DELETE FROM branches WHERE id=$id");
        return json_encode(['result' => true]);
    }

    /**
     * Create or rename an area (a ward/section inside a department).
     *
     * The name is unique because area_approver and the seed migration both
     * address areas by name; two "STATION 2" rows would make "who approves
     * here" depend on row order.
     */
    /**
     * An area id that is safe to store: an existing, active area that actually
     * belongs to the department the employee is being filed under.
     *
     * Checked server-side because the form only DISABLES the mismatched options
     * — disabled options are a hint to the person filling the form, not a
     * constraint on what a POST can carry. A mismatch would put someone on a
     * ward their own department does not contain, and every scoping query
     * downstream reads area first.
     */
    private function normalizeAreaId($raw, $department_id)
    {
        $aid = (int) $raw;
        if ($aid <= 0) return null;
        $r = $this->db->query("SELECT department_id FROM area WHERE id = $aid AND status = 1");
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) return null;
        if ($department_id !== null && (int) $row['department_id'] !== (int) $department_id) return null;
        return $aid;
    }

    function save_area()
    {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $dept = (int) ($_POST['department_id'] ?? 0);

        if ($name === '')  return ['result' => false, 'message' => 'Area name is required.'];
        if ($dept <= 0)    return ['result' => false, 'message' => 'Pick the department this area belongs to.'];

        $d = $this->db->query("SELECT id FROM department WHERE id = $dept");
        if (!$d || !$d->num_rows) return ['result' => false, 'message' => 'That department no longer exists.'];

        $stmt = $this->db->prepare("SELECT id FROM area WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return ['result' => false, 'message' => 'An area named "' . $name . '" already exists.'];
        }

        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE area SET name = ?, department_id = ? WHERE id = ?");
            $stmt->bind_param('sii', $name, $dept, $id);
        } else {
            $stmt = $this->db->prepare("INSERT INTO area (name, department_id) VALUES (?, ?)");
            $stmt->bind_param('si', $name, $dept);
        }
        if (!$stmt->execute()) return ['result' => false, 'message' => 'Could not save: ' . $this->db->error];
        return ['result' => true, 'id' => $id > 0 ? $id : (int) $this->db->insert_id];
    }

    /**
     * Replace an area's approver list, stage by stage.
     *
     * Written as delete-then-insert inside a transaction because the form posts
     * the COMPLETE intended set: anything the user cleared has to disappear, and
     * diffing row by row would leave a stage half-updated if one insert failed.
     * 'hr' is refused — HR is one office for the whole hospital and is resolved
     * by role, not per area; storing it here would create 36 places to forget.
     */
    function save_area_approvers()
    {
        $area = (int) ($_POST['area_id'] ?? 0);
        if ($area <= 0) return ['result' => false, 'message' => 'No area given.'];
        $a = $this->db->query("SELECT id FROM area WHERE id = $area");
        if (!$a || !$a->num_rows) return ['result' => false, 'message' => 'That area no longer exists.'];

        $allowed = ['sec', 'sup', 'admin'];
        $posted  = $_POST['stage'] ?? [];
        if (!is_array($posted)) $posted = [];

        $this->db->begin_transaction();
        try {
            $this->db->query("DELETE FROM area_approver WHERE area_id = $area");
            $ins = $this->db->prepare("INSERT IGNORE INTO area_approver (area_id, stage, user_id) VALUES (?,?,?)");
            $n = 0;
            foreach ($allowed as $stage) {
                foreach ((array) ($posted[$stage] ?? []) as $uid) {
                    $uid = (int) $uid;
                    if ($uid <= 0) continue;
                    $u = $this->db->query("SELECT id FROM users WHERE id = $uid AND status = 1");
                    if (!$u || !$u->num_rows) continue;
                    $ins->bind_param('isi', $area, $stage, $uid);
                    $ins->execute();
                    $n++;
                }
            }
            $this->db->commit();
            return ['result' => true, 'saved' => $n];
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Could not save approvers: ' . $e->getMessage()];
        }
    }

    function save_department()
    {
        extract($_POST);

        $data = " name='$name' ";

        if (empty($id)) {
            $save = $this->db->query("INSERT INTO department set " . $data);

            if ($save) {
                return 1;
            }
        } else {
            $save = $this->db->query("UPDATE department set " . $data . " where id=" . $id);

            if ($save) {
                return 2;
            }
        }
    }

    function delete_department()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM department where id = " . (int)$id);

        if ($delete) {
            return 1;
        }
    }

    function save_work_schedule()
    {
        $id            = intval($_POST['id'] ?? 0);
        $description   = trim($_POST['description'] ?? '');
        $start_time    = trim($_POST['start_time'] ?? '');
        $end_time      = trim($_POST['end_time'] ?? '');
        $total_hours   = floatval($_POST['total_hours'] ?? 8);
        $break_minutes = intval($_POST['break_minutes'] ?? 60);
        // When the break falls (HH:MM). Blank = NULL = middle of the shift
        // (dtr_break_window). Only the paid part of a day is late / undertime /
        // worked, so this decides e.g. whether a 12:34 PM arrival on 8–5 lost the
        // whole lunch hour or just the 26 minutes of it they were present for.
        $break_start   = trim($_POST['break_start'] ?? '');
        $break_start   = ($break_start !== '' && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $break_start)) ? $break_start : null;
        $is_graveyard  = intval($_POST['is_graveyard'] ?? 0);
        $has_nsd       = intval($_POST['has_nsd'] ?? 0);
        $nsd_rate      = floatval($_POST['nsd_rate'] ?? 0);

        if (!$description || !$start_time || !$end_time) {
            return ['result' => false, 'message' => 'Description, start time and end time are required'];
        }

        if ($id) {
            $stmt = $this->db->prepare(
                "UPDATE work_schedules SET description=?, start_time=?, end_time=?, total_hours=?,
                 break_minutes=?, break_start=?, is_graveyard=?, has_nsd=?, nsd_rate=? WHERE id=?"
            );
            $stmt->bind_param(
                'sssdisiidi',
                $description,
                $start_time,
                $end_time,
                $total_hours,
                $break_minutes,
                $break_start,
                $is_graveyard,
                $has_nsd,
                $nsd_rate,
                $id
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO work_schedules (description, start_time, end_time, total_hours,
                 break_minutes, break_start, is_graveyard, has_nsd, nsd_rate) VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'sssdisiid',
                $description,
                $start_time,
                $end_time,
                $total_hours,
                $break_minutes,
                $break_start,
                $is_graveyard,
                $has_nsd,
                $nsd_rate
            );
        }
        $ok = $stmt->execute();
        if (!$ok) return ['result' => false, 'message' => $stmt->error];
        return ['result' => true, 'message' => 'Saved'];
    }

    function delete_work_schedule()
    {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("UPDATE work_schedules SET status=0 WHERE id=?");
        $stmt->bind_param('i', $id);
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Deleted'];
    }

    function assign_employee_schedule()
    {
        $employee_id    = intval($_POST['employee_id'] ?? 0);
        $schedule_id    = intval($_POST['schedule_id'] ?? 0);
        $effective_from = trim($_POST['effective_from'] ?? date('Y-m-d'));
        $notes          = trim($_POST['notes'] ?? '');
        // Absent = keep the employee's current rest days (see applyScheduleChange).
        $rest_days      = array_key_exists('rest_days', $_POST) ? $this->normalizeRestDays($_POST['rest_days']) : null;
        $changed_by     = $_SESSION['login_id'] ?? null;

        if (!$employee_id || !$schedule_id) {
            return ['result' => false, 'message' => 'employee_id and schedule_id are required'];
        }

        $this->db->begin_transaction();
        try {
            // Same period logic the bulk roster uses — this used to carry its
            // own copy that amended whichever row was open, which refused any
            // change dated before a schedule already planned for a later date.
            $r = $this->applyScheduleChange($employee_id, $schedule_id, $effective_from, $notes, $changed_by, $rest_days);

            $this->db->commit();
            if ($r === 'unchanged') {
                return ['result' => true, 'message' => 'No change — already on this schedule.'];
            }

            // Bell + FCM push to the employee — the roster bulk path and the
            // planner already send this; the single-assign modal never did.
            $this->notifyScheduleChange($employee_id, $schedule_id, $effective_from);

            // Attendance already recorded under the old shift stays frozen until a
            // batch Recompute — count those rows so the UI can warn the admin
            // immediately instead of leaving it to the dashboard card.
            $stale = $this->db->query(
                "SELECT COUNT(*) AS n FROM DTR_details d
                 INNER JOIN DTR ON DTR.id = d.ddtr_id
                 WHERE d.employee_id = $employee_id AND DTR.status <> 2
                   AND " . dtr_schedule_mismatch_where('d')
            );
            $staleRows = $stale ? (int) ($stale->fetch_assoc()['n'] ?? 0) : 0;

            return ['result' => true, 'message' => 'Schedule assigned', 'stale_rows' => $staleRows];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove one period from an employee's schedule history. ADMIN ONLY —
     * a period defines the shift and rest days that DTR and payroll were
     * already computed against, so dropping one rewrites history.
     *
     * When the deleted row was the open (current) period, the newest remaining
     * one is reopened (effective_to = NULL) so the employee is never left
     * without a current schedule.
     */
    function delete_employee_schedule()
    {
        if (!$this->is_admin_session()) {
            return ['result' => false, 'message' => 'Only an Administrator may delete a schedule period.'];
        }

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing schedule id'];

        $stmt = $this->db->prepare("SELECT employee_id, effective_to FROM employee_schedules WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Schedule period not found.'];

        $employee_id = (int) $row['employee_id'];
        $was_open    = $row['effective_to'] === null;

        $this->db->begin_transaction();
        try {
            $del = $this->db->prepare("DELETE FROM employee_schedules WHERE id=?");
            $del->bind_param('i', $id);
            $del->execute();

            if ($was_open) {
                $prev = $this->db->prepare(
                    "SELECT id FROM employee_schedules WHERE employee_id=?
                     ORDER BY effective_from DESC, id DESC LIMIT 1"
                );
                $prev->bind_param('i', $employee_id);
                $prev->execute();
                if ($p = $prev->get_result()->fetch_assoc()) {
                    $reopen = $this->db->prepare("UPDATE employee_schedules SET effective_to=NULL WHERE id=?");
                    $reopen->bind_param('i', $p['id']);
                    $reopen->execute();
                }
            }

            $this->db->commit();
            return ['result' => true, 'message' => 'Schedule period deleted'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete enrolled fingerprint template(s) for an employee. ADMIN ONLY.
     *
     * $_POST: employee_id, source ('device' | 'kiosk'),
     *         finger_index (canonical code, or '' to clear every finger).
     * The two scanners keep their templates in separate tables (see
     * component/finger_hands.php), so the source picks which one is touched.
     */
    function delete_employee_fingerprint()
    {
        if (!$this->is_admin_session()) {
            return ['result' => false, 'message' => 'Only an Administrator may delete a fingerprint.'];
        }

        $employee_id  = (int) ($_POST['employee_id'] ?? 0);
        $finger_index = trim((string) ($_POST['finger_index'] ?? ''));
        $source       = ($_POST['source'] ?? 'device') === 'kiosk' ? 'kiosk' : 'device';
        if (!$employee_id) return ['result' => false, 'message' => 'Missing employee_id'];

        // Older rows may hold legacy codes ("finger-1"), so only the shape is
        // checked — enough to keep an arbitrary value out of the DELETE.
        if ($finger_index !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $finger_index)) {
            return ['result' => false, 'message' => 'Invalid finger'];
        }

        $sql = $source === 'kiosk'
            ? "DELETE FROM biometric_kiosk_templates WHERE employee_id=? AND format='sourceafis'"
            : "DELETE FROM employee_fingerprints WHERE employee_id=?";
        if ($finger_index !== '') $sql .= " AND finger_index=?";

        $stmt = $this->db->prepare($sql);
        if ($finger_index !== '') $stmt->bind_param('is', $employee_id, $finger_index);
        else                      $stmt->bind_param('i', $employee_id);

        try {
            $stmt->execute();
        } catch (Exception $e) {
            return ['result' => false, 'message' => 'Failed to delete fingerprint: ' . $e->getMessage()];
        }

        $n = $stmt->affected_rows;
        return [
            'result'  => true,
            'deleted' => $n,
            'message' => $n === 0
                ? 'No matching fingerprint found.'
                : ($finger_index !== '' ? 'Fingerprint deleted' : $n . ' fingerprint(s) deleted'),
        ];
    }

    // Normalize a rest-days value into a canonical CSV of weekday numbers 0..6 (0=Sun … 6=Sat),
    // sorted, de-duped, out-of-range values dropped. Accepts a CSV string or an array.
    // Returns '' when nothing valid was given (i.e. no rest day).
    private function normalizeRestDays($raw)
    {
        if ($raw === null) return '';
        $parts = is_array($raw) ? $raw : explode(',', (string)$raw);
        $days = [];
        foreach ($parts as $p) {
            if ($p === '' || !is_numeric($p)) continue;
            $d = (int)$p;
            if ($d >= 0 && $d <= 6) $days[$d] = true;
        }
        $days = array_keys($days);
        sort($days);
        return implode(',', $days);
    }

    // Given an employee's schedule periods (each: effective_from, effective_to, rest_days),
    // return the rest_days CSV in effect on $ymd, or '' if none matches.
    private function restDaysForDate($periods, $ymd)
    {
        $p = pick_schedule_window($periods, $ymd);
        return $p ? (string)$p['rest_days'] : '';
    }

    /**
     * Standard daily hours for $ymd from the same preloaded schedule periods
     * restDaysForDate() reads — i.e. how many worked hours make one full day
     * for this employee on that date.
     *
     * Falls back to PAYROLL_DEFAULT_DAY_HOURS only when the employee has NO
     * schedule at all. A date outside every period still resolves through
     * pick_schedule_window() — assuming a flat 8-hour day for someone on a
     * 7-hour shift silently restates `present` (91h read as 11.375 days
     * instead of 13) and understates their basic pay.
     */
    private function dayHoursForDate($periods, $ymd)
    {
        $p = pick_schedule_window($periods, $ymd);
        return $p ? day_hours_or_default($p['total_hours'] ?? null)
                  : (float) PAYROLL_DEFAULT_DAY_HOURS;
    }

    // True if $ymd (a Y-m-d date) falls on one of the employee's rest days per $periods.
    // Published duty-roster rest flags for the payroll range being processed,
    // keyed [employee_id][Y-m-d]. Loaded once per run by loadDutyRestMap().
    private $dutyRestMap = [];

    /**
     * Preload the duty roster's rest flags for one date range.
     *
     * The tallies that walk DATES rather than DTR rows — expected working days
     * for a monthly rate, paid-leave eligibility — ask isRestDay() about days
     * that may have no attendance row at all, so there is no stamp to read.
     * For a rotating employee the weekday CSV underneath is meaningless (their
     * day off moves through the week), and without this map a nurse's absences
     * were counted against a Mon-Fri week they were never on.
     */
    private function loadDutyRestMap($date_from, $date_to)
    {
        $this->dutyRestMap = [];
        $q = $this->db->prepare(
            "SELECT employee_id, work_date, is_rest_day FROM employee_day_schedule
             WHERE status = 1 AND work_date BETWEEN ? AND ?"
        );
        if (!$q) return;                    // table not migrated yet → weekday CSV as before
        $q->bind_param('ss', $date_from, $date_to);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) {
            $this->dutyRestMap[(int) $row['employee_id']][$row['work_date']] = (int) $row['is_rest_day'];
        }
    }

    // $employee_id is optional only so older callers keep working; pass it
    // whenever you have it, or a rostered employee gets the weekly answer.
    private function isRestDay($periods, $ymd, $employee_id = null)
    {
        // A published duty-roster day is the specific answer for that date and
        // beats the weekly pattern — same precedence as resolve_employee_schedule.
        if ($employee_id !== null && isset($this->dutyRestMap[(int) $employee_id][$ymd])) {
            return $this->dutyRestMap[(int) $employee_id][$ymd] === 1;
        }
        $rd = $this->restDaysForDate($periods, $ymd);
        if ($rd === '') return false;
        $w = (int)date('w', strtotime($ymd)); // 0=Sun … 6=Sat
        return in_array($w, array_map('intval', explode(',', $rd)), true);
    }

    /**
     * The schedule period in effect on $ymd, from the same preloaded periods
     * restDaysForDate() reads (they carry ws.has_nsd / ws.nsd_rate as well).
     * Covering period, else the nearest — returning null for an uncovered date
     * priced night differential at rate 0, so hours logged 10PM–6AM were paid
     * nothing at all. Null now means only "this employee has no schedule".
     */
    private function scheduleOnDate($periods, $ymd)
    {
        return pick_schedule_window($periods, $ymd);
    }

    /**
     * The rest days an employee already has around $ymd — the period starting
     * on/before that date, else their earliest period. Used when a caller
     * changes ONLY the shift and sends no rest_days at all.
     */
    private function inheritedRestDays($emp, $ymd)
    {
        $emp = (int) $emp;
        $q = $this->db->prepare(
            "SELECT rest_days FROM employee_schedules
             WHERE employee_id=? AND effective_from <= ?
             ORDER BY effective_from DESC LIMIT 1"
        );
        $q->bind_param('is', $emp, $ymd);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        if (!$row) {
            $q2 = $this->db->prepare(
                "SELECT rest_days FROM employee_schedules
                 WHERE employee_id=? ORDER BY effective_from ASC LIMIT 1"
            );
            $q2->bind_param('i', $emp);
            $q2->execute();
            $row = $q2->get_result()->fetch_assoc();
        }
        return $row ? (string)$row['rest_days'] : '';
    }

    // Core shift-assignment for ONE employee. Closes the open period / opens a new one
    // (with same-day correction). Caller owns the transaction. Returns 'updated' | 'unchanged' | 'skipped'.
    // $rest_days === null means "keep whatever this employee already has" — a shift-only
    // change must never silently rewrite rest days (a defaulted '0' wiped Saturdays off).
    private function applyScheduleChange($emp, $schedule_id, $effective_from, $notes, $changed_by, $rest_days = null)
    {
        $emp = (int) $emp;
        $schedule_id = (int) $schedule_id;

        // The period COVERING the effective date — deliberately not "the open
        // row". Once a change is scheduled in advance the open row is a FUTURE
        // period, and amending that would reject (or mis-date) any change meant
        // to take effect before it.
        $cov = $this->db->prepare(
            "SELECT id, schedule_id, effective_from, effective_to, rest_days
             FROM employee_schedules
             WHERE employee_id=? AND effective_from <= ?
               AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from DESC LIMIT 1"
        );
        $cov->bind_param('iss', $emp, $effective_from, $effective_from);
        $cov->execute();
        $period = $cov->get_result()->fetch_assoc();

        $rest_days = $this->normalizeRestDays(
            $rest_days === null
                ? ($period ? $period['rest_days'] : $this->inheritedRestDays($emp, $effective_from))
                : $rest_days
        );

        if ($period) {
            if ((int)$period['schedule_id'] === $schedule_id && (string)$period['rest_days'] === $rest_days) return 'unchanged';

            if ($period['effective_from'] === $effective_from) {
                // Same start date: correct that period in place rather than
                // stacking a zero-length one on top of it.
                $u = $this->db->prepare(
                    "UPDATE employee_schedules SET schedule_id=?, notes=?, rest_days=?, changed_by=? WHERE id=?"
                );
                $u->bind_param('issii', $schedule_id, $notes, $rest_days, $changed_by, $period['id']);
                $u->execute();
                return 'updated';
            }

            // Split the covering period: close it the day before, and let the
            // new one INHERIT its end date so a period already scheduled after
            // it survives instead of being overlapped by an open-ended row.
            $prev_date = date('Y-m-d', strtotime($effective_from . ' -1 day'));
            $c = $this->db->prepare("UPDATE employee_schedules SET effective_to=? WHERE id=?");
            $c->bind_param('si', $prev_date, $period['id']);
            $c->execute();
            $newTo = $period['effective_to'];        // null stays null → open-ended
            $ins = $this->db->prepare(
                "INSERT INTO employee_schedules (employee_id, schedule_id, effective_from, effective_to, notes, rest_days, changed_by)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $ins->bind_param('iissssi', $emp, $schedule_id, $effective_from, $newTo, $notes, $rest_days, $changed_by);
            $ins->execute();
            return 'updated';
        }

        // Nothing covers the date. Any period starting later must still begin
        // on time, so this one is capped the day before the earliest of them —
        // without that cap an open-ended row would swallow them.
        $nx = $this->db->prepare(
            "SELECT MIN(effective_from) AS nxt FROM employee_schedules WHERE employee_id=? AND effective_from > ?"
        );
        $nx->bind_param('is', $emp, $effective_from);
        $nx->execute();
        $nxt   = $nx->get_result()->fetch_assoc()['nxt'] ?? null;
        $newTo = $nxt ? date('Y-m-d', strtotime($nxt . ' -1 day')) : null;

        $ins = $this->db->prepare(
            "INSERT INTO employee_schedules (employee_id, schedule_id, effective_from, effective_to, notes, rest_days, changed_by)
             VALUES (?,?,?,?,?,?,?)"
        );
        $ins->bind_param('iissssi', $emp, $schedule_id, $effective_from, $newTo, $notes, $rest_days, $changed_by);
        $ins->execute();
        return 'updated';
    }

    // Notify an employee that their work schedule was changed/planned.
    private function notifyScheduleChange($emp, $schedule_id, $effective_from)
    {
        $desc = 'a new shift';
        $srow = $this->db->query("SELECT description FROM work_schedules WHERE id=" . (int)$schedule_id)->fetch_assoc();
        if ($srow) $desc = $srow['description'];
        $when = date('M j, Y', strtotime($effective_from));
        $future = strtotime($effective_from) > strtotime(date('Y-m-d'));
        $title = $future ? 'Upcoming schedule change' : 'Your schedule changed';
        $msg = ($future ? 'Starting ' : 'Effective ') . $when . ', your shift is "' . $desc . '".';
        $this->notifyEmployee($emp, $title, $msg, 'ri-time-line', 'info', 'employee-portal.php?tab=info');
    }

    // Roster page: assign a shift to one or many employees at once. Applies immediately to
    // employee_schedules and notifies each affected employee.
    // Accepts employee_id (single) or employee_ids[] (bulk).
    function roster_assign_schedule()
    {
        $schedule_id    = intval($_POST['schedule_id'] ?? 0);
        $effective_from = trim($_POST['effective_from'] ?? date('Y-m-d'));
        $notes          = trim($_POST['notes'] ?? '');
        // No rest_days in the request = shift-only change → keep each employee's
        // existing rest days. This used to default to '0' (Sunday), so a bulk
        // shift assignment silently erased everyone's other rest day.
        $rest_days      = array_key_exists('rest_days', $_POST) ? $_POST['rest_days'] : null;
        $changed_by     = $_SESSION['login_id'] ?? null;

        // Normalize to a list of employee ids
        $ids = [];
        if (isset($_POST['employee_ids'])) {
            $raw = $_POST['employee_ids'];
            if (!is_array($raw)) $raw = explode(',', $raw);
            foreach ($raw as $v) {
                $v = intval($v);
                if ($v) $ids[] = $v;
            }
        } elseif (isset($_POST['employee_id'])) {
            $v = intval($_POST['employee_id']);
            if ($v) $ids[] = $v;
        }
        $ids = array_values(array_unique($ids));

        if (!$schedule_id || empty($ids)) {
            return ['result' => false, 'message' => 'Please select at least one employee and a shift.'];
        }
        if (!strtotime($effective_from)) {
            return ['result' => false, 'message' => 'Invalid effective date.'];
        }

        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $notify = [];

        $this->db->begin_transaction();
        try {
            foreach ($ids as $emp) {
                $r = $this->applyScheduleChange($emp, $schedule_id, $effective_from, $notes, $changed_by, $rest_days);
                if ($r === 'updated') {
                    $updated++;
                    $notify[] = $emp;
                } elseif ($r === 'unchanged') $unchanged++;
                else $skipped++;
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }

        // Notifications after commit so a notify failure can't roll back the schedule change
        foreach ($notify as $emp) $this->notifyScheduleChange($emp, $schedule_id, $effective_from);

        $parts = [];
        if ($updated)   $parts[] = "$updated updated";
        if ($unchanged) $parts[] = "$unchanged already on that shift";
        if ($skipped)   $parts[] = "$skipped skipped (date is before their current shift's start)";
        return [
            'result'    => true,
            'updated'   => $updated,
            'unchanged' => $unchanged,
            'skipped'   => $skipped,
            'message'   => $parts ? implode(', ', $parts) . '.' : 'No changes made.'
        ];
    }

    // Roster page: bulk-update ONLY the rest days for the selected employees, on their
    // current active schedule period (effective_to IS NULL) — no shift change, no new period.
    // Employees with no active schedule are skipped.
    function roster_update_rest_days()
    {
        $rest_days  = $this->normalizeRestDays($_POST['rest_days'] ?? '');
        $changed_by = $_SESSION['login_id'] ?? null;

        $ids = [];
        if (isset($_POST['employee_ids'])) {
            $raw = $_POST['employee_ids'];
            if (!is_array($raw)) $raw = explode(',', $raw);
            foreach ($raw as $v) {
                $v = intval($v);
                if ($v) $ids[] = $v;
            }
        } elseif (isset($_POST['employee_id'])) {
            $v = intval($_POST['employee_id']);
            if ($v) $ids[] = $v;
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return ['result' => false, 'message' => 'Please select at least one employee.'];
        }

        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $notify = [];

        $this->db->begin_transaction();
        try {
            $today = date('Y-m-d');
            $sel = $this->db->prepare(
                "SELECT schedule_id FROM employee_schedules
                 WHERE employee_id=? AND effective_from <= ?
                   AND (effective_to IS NULL OR effective_to >= ?)
                 ORDER BY effective_from DESC LIMIT 1"
            );
            foreach ($ids as $emp) {
                $sel->bind_param('iss', $emp, $today, $today);
                $sel->execute();
                $cur = $sel->get_result()->fetch_assoc();
                if (!$cur) { $skipped++; continue; }              // no active schedule to attach rest days to
                // Same shift + new rest days, effective today, through
                // applyScheduleChange — the change lands as a NEW period so
                // past dates keep the rest days they were computed under. The
                // old in-place UPDATE rewrote history and retro-repainted the
                // off-day markers on closed DTR sheets.
                $r = $this->applyScheduleChange($emp, (int)$cur['schedule_id'], $today, 'Rest days update', $changed_by, $rest_days);
                if ($r === 'updated') {
                    $updated++;
                    $notify[] = $emp;
                } elseif ($r === 'unchanged') $unchanged++;
                else $skipped++;
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }

        // Notify affected employees of their new rest days (after commit).
        $label = $this->restDaysLabel($rest_days);
        foreach ($notify as $emp) {
            $this->notifyEmployee($emp, 'Your rest days changed',
                'Your rest days are now: ' . $label . '.', 'ri-moon-line', 'info', 'employee-portal.php?tab=info');
        }

        $parts = [];
        if ($updated)   $parts[] = "$updated updated";
        if ($unchanged) $parts[] = "$unchanged already set";
        if ($skipped)   $parts[] = "$skipped skipped (no active schedule)";
        return [
            'result'    => true,
            'updated'   => $updated,
            'unchanged' => $unchanged,
            'skipped'   => $skipped,
            'message'   => ($parts ? implode(', ', $parts) . '.' : 'No changes made.')
        ];
    }

    // Human-readable label for a rest_days CSV, e.g. "0,6" -> "Sun, Sat"; '' -> "None".
    private function restDaysLabel($csv)
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $days = array_filter(array_map('intval', $csv === '' ? [] : explode(',', $csv)), function ($d) { return $d >= 0 && $d <= 6; });
        if (empty($days)) return 'None';
        return implode(', ', array_map(function ($d) use ($names) { return $names[$d]; }, $days));
    }

    // Roster page: bulk-set the pay Rate Type (daily | monthly | fixed) for the
    // selected employees. This is a compensation setting on the employee record,
    // not a schedule change — it affects how the NEXT payroll calc pays them.
    function roster_update_rate_type()
    {
        $rate_type = $_POST['rate_type'] ?? '';
        if (!in_array($rate_type, ['daily', 'monthly', 'fixed'], true)) {
            return ['result' => false, 'message' => 'Please choose a valid rate type (Daily, Monthly, or Fixed).'];
        }

        $ids = [];
        if (isset($_POST['employee_ids'])) {
            $raw = $_POST['employee_ids'];
            if (!is_array($raw)) $raw = explode(',', $raw);
            foreach ($raw as $v) {
                $v = intval($v);
                if ($v) $ids[] = $v;
            }
        } elseif (isset($_POST['employee_id'])) {
            $v = intval($_POST['employee_id']);
            if ($v) $ids[] = $v;
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return ['result' => false, 'message' => 'Please select at least one employee.'];
        }

        $in = implode(',', array_map('intval', $ids));
        $stmt = $this->db->prepare("UPDATE employee SET rate_type = ? WHERE id IN ($in)");
        $stmt->bind_param('s', $rate_type);
        if (!$stmt->execute()) {
            return ['result' => false, 'message' => 'Failed to update: ' . $this->db->error];
        }

        $label = ['daily' => 'Daily', 'monthly' => 'Monthly', 'fixed' => 'Fixed'][$rate_type];
        return [
            'result'  => true,
            'updated' => $stmt->affected_rows,
            'message' => count($ids) . ' employee(s) set to ' . $label . ' rate.',
        ];
    }

    // ---- Schedule Planner (staging area) -------------------------------------
    // Drafts sit in schedule_plan and are invisible to employees until applied.

    // Add / queue a planned change for one or many employees (one pending draft per employee — upsert).
    function plan_add_schedule()
    {
        $schedule_id    = intval($_POST['schedule_id'] ?? 0);
        $effective_from = trim($_POST['effective_from'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        // Absent = keep each employee's current rest days. schedule_plan.rest_days is
        // NOT NULL, so "keep" is resolved per employee here and shown in the plan table.
        $rest_days      = array_key_exists('rest_days', $_POST) ? $this->normalizeRestDays($_POST['rest_days']) : null;
        $created_by     = $_SESSION['login_id'] ?? null;

        $ids = [];
        if (isset($_POST['employee_ids'])) {
            $raw = $_POST['employee_ids'];
            if (!is_array($raw)) $raw = explode(',', $raw);
            foreach ($raw as $v) {
                $v = intval($v);
                if ($v) $ids[] = $v;
            }
        } elseif (isset($_POST['employee_id'])) {
            $v = intval($_POST['employee_id']);
            if ($v) $ids[] = $v;
        }
        $ids = array_values(array_unique($ids));

        if (!$schedule_id || empty($ids)) {
            return ['result' => false, 'message' => 'Please select at least one employee and a shift.'];
        }
        if (!$effective_from || !strtotime($effective_from)) {
            return ['result' => false, 'message' => 'Please choose a valid effective date.'];
        }

        $added = 0;
        $this->db->begin_transaction();
        try {
            foreach ($ids as $emp) {
                // One pending draft per employee — replace an existing pending one
                $del = $this->db->prepare("DELETE FROM schedule_plan WHERE employee_id=? AND status=0");
                $del->bind_param('i', $emp);
                $del->execute();

                $emp_rest = $rest_days === null
                    ? $this->normalizeRestDays($this->inheritedRestDays($emp, $effective_from))
                    : $rest_days;

                $ins = $this->db->prepare(
                    "INSERT INTO schedule_plan (employee_id, schedule_id, effective_from, notes, rest_days, status, created_by)
                     VALUES (?,?,?,?,?,0,?)"
                );
                $ins->bind_param('iisssi', $emp, $schedule_id, $effective_from, $notes, $emp_rest, $created_by);
                $ins->execute();
                $added++;
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }

        return ['result' => true, 'added' => $added, 'message' => "$added change(s) added to the plan."];
    }

    // Pending drafts, newest first, with employee + shift detail for the planner panel.
    function plan_list()
    {
        $rows = [];
        $q = $this->db->query("
            SELECT sp.id, sp.employee_id, sp.schedule_id, sp.effective_from, sp.notes, sp.rest_days,
                   CONCAT(e.lastname, ', ', e.firstname) AS emp_name, e.employee_no,
                   ws.description AS shift_desc, ws.start_time, ws.end_time,
                   cur_ws.description AS cur_shift_desc
            FROM schedule_plan sp
            INNER JOIN employee e ON e.id = sp.employee_id
            INNER JOIN work_schedules ws ON ws.id = sp.schedule_id
            LEFT JOIN employee_schedules es ON es.employee_id = e.id AND es.effective_to IS NULL
            LEFT JOIN work_schedules cur_ws ON cur_ws.id = es.schedule_id
            WHERE sp.status = 0
            ORDER BY sp.effective_from ASC, e.lastname ASC
        ");
        if ($q) while ($row = $q->fetch_assoc()) $rows[] = $row;
        return ['result' => true, 'count' => count($rows), 'data' => $rows];
    }

    // Remove a single pending draft.
    function plan_remove()
    {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing plan id.'];
        $stmt = $this->db->prepare("DELETE FROM schedule_plan WHERE id=? AND status=0");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return ['result' => true, 'message' => 'Removed from plan.'];
    }

    // Clear the whole pending plan.
    function plan_clear()
    {
        $this->db->query("DELETE FROM schedule_plan WHERE status=0");
        return ['result' => true, 'message' => 'Plan cleared.'];
    }

    // Apply every pending draft: commit to employee_schedules, notify each employee, mark applied.
    function plan_apply_all()
    {
        $changed_by = $_SESSION['login_id'] ?? null;

        $drafts = [];
        $q = $this->db->query("SELECT id, employee_id, schedule_id, effective_from, notes, rest_days
                               FROM schedule_plan WHERE status=0 ORDER BY effective_from ASC, id ASC");
        if ($q) while ($row = $q->fetch_assoc()) $drafts[] = $row;

        if (empty($drafts)) {
            return ['result' => false, 'message' => 'There are no planned changes to apply.'];
        }

        $applied = 0;
        $unchanged = 0;
        $skipped = 0;
        $notify = [];

        $this->db->begin_transaction();
        try {
            foreach ($drafts as $d) {
                $r = $this->applyScheduleChange($d['employee_id'], $d['schedule_id'], $d['effective_from'], $d['notes'], $changed_by, $d['rest_days'] ?? null);
                if ($r === 'updated') {
                    $applied++;
                    $notify[] = $d;
                } elseif ($r === 'unchanged') $unchanged++;
                else $skipped++;

                // Mark this draft as applied regardless of outcome (it has been processed)
                $m = $this->db->prepare("UPDATE schedule_plan SET status=1, applied_by=?, applied_at=NOW() WHERE id=?");
                $m->bind_param('ii', $changed_by, $d['id']);
                $m->execute();
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }

        foreach ($notify as $d) $this->notifyScheduleChange($d['employee_id'], $d['schedule_id'], $d['effective_from']);

        $parts = [];
        if ($applied)   $parts[] = "$applied applied";
        if ($unchanged) $parts[] = "$unchanged already on that shift";
        if ($skipped)   $parts[] = "$skipped skipped (date before current shift start)";
        return [
            'result'  => true,
            'applied' => $applied,
            'message' => ($parts ? implode(', ', $parts) . '.' : 'Nothing to apply.') . ' Employees were notified.'
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
     * DUTY ROSTER — the per-day cutoff grid for rotating staff
     *
     * The period roster above (employee_schedules) answers "which shift, from
     * when to when" with rest days as a fixed weekday CSV. Nurses rotate — the
     * shift changes every couple of days and the day off moves through the
     * week — so they are planned on a DAY grid instead, one row per employee
     * per date in employee_day_schedule.
     *
     * Three states decide what may be edited, and they follow the DTR, not the
     * calendar:
     *   free    — no attendance row yet. Edit freely; the shift is only stamped
     *             onto a DTR row when the employee actually punches.
     *   punched — an attendance row exists in an OPEN batch. Editable, but its
     *             figures were frozen under the old shift, so the change needs
     *             a Recompute to apply. The existing stale-schedule detector
     *             finds these and blocks batch approval until it is run.
     *   locked  — the batch is approved (status 2, one-way). Rejected here, not
     *             just hidden in the UI: recompute_dtr refuses locked batches,
     *             so an edit would leave the roster claiming a shift that was
     *             never what the employee was paid under.
     * ══════════════════════════════════════════════════════════════════════ */

    // "YYYY-MM-1" / "YYYY-MM-2" → the semi-monthly cutoff's date range.
    // The client names a PERIOD rather than two free dates so a hand-crafted
    // request cannot paint an arbitrary span (e.g. a whole year) in one call.
    public function dutyPeriodRange($period)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-([12])$/', (string) $period, $m)) return null;
        $y = (int) $m[1];
        $mo = (int) $m[2];
        if ($mo < 1 || $mo > 12 || $y < 2000 || $y > 2100) return null;
        if ((int) $m[3] === 1) {
            return ['from' => sprintf('%04d-%02d-01', $y, $mo), 'to' => sprintf('%04d-%02d-15', $y, $mo)];
        }
        $last = (int) date('t', mktime(0, 0, 0, $mo, 1, $y));
        return ['from' => sprintf('%04d-%02d-16', $y, $mo), 'to' => sprintf('%04d-%02d-%02d', $y, $mo, $last)];
    }

    // The cutoff before this one — for "Copy last cutoff".
    private function dutyPrevPeriod($period)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-([12])$/', (string) $period, $m)) return null;
        if ((int) $m[3] === 2) return $m[1] . '-' . $m[2] . '-1';
        $t = strtotime($m[1] . '-' . $m[2] . '-01 -1 month');
        return date('Y-m', $t) . '-2';
    }

    /**
     * Which employee-days already carry attendance, and whether that batch is
     * locked. Keyed "employee_id|Y-m-d" => 'locked' | 'punched'; anything absent
     * is free. One query for the whole grid — the alternative is a lookup per
     * cell, and a 40 × 15 grid is 600 of them.
     */
    public function dutyZoneMap($from, $to, array $empIds): array
    {
        $out = [];
        if (empty($empIds)) return $out;
        $ids = implode(',', array_map('intval', $empIds));
        $res = $this->db->query(
            "SELECT d.employee_id AS eid, DATE(d.date_time) AS wd, MAX(b.status = 2) AS locked
             FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
             WHERE d.employee_id IN ($ids)
               AND DATE(d.date_time) BETWEEN '" . $this->db->real_escape_string($from) . "'
                                         AND '" . $this->db->real_escape_string($to) . "'
             GROUP BY d.employee_id, DATE(d.date_time)"
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $out[$r['eid'] . '|' . $r['wd']] = ((int) $r['locked'] === 1) ? 'locked' : 'punched';
        }
        return $out;
    }

    /**
     * The department this session is locked to, or 0 for an unscoped role.
     *
     * Wraps dept_scope_id() so the duty-roster code has one dependency to load
     * rather than a require_once at every call site — and so it degrades to
     * "unscoped" rather than fatal if the file is ever moved.
     */
    public function dutyScopeId(): int
    {
        $this->loadScopeHelpers();
        return function_exists('dept_scope_id') ? dept_scope_id() : 0;
    }

    /** dept-scope.php is not loaded on every entry point; pull it in on demand. */
    private function loadScopeHelpers(): void
    {
        // dept-scope.php resolves the session's areas through $GLOBALS['conn'],
        // and returns [] — "unscoped", i.e. every check passes — when it cannot
        // find a connection there.
        //
        // Pages set that global: they `include 'db_connect.php'` at file scope.
        // ajax.php does not — it includes admin_class.php, whose constructor
        // includes db_connect.php from INSIDE __construct(), so $conn is a
        // method local and $GLOBALS['conn'] is never set. dept-scope.php's own
        // fallback cannot recover it either: its require_once is a no-op by then
        // (the file is already loaded) and would bind $conn inside a function
        // anyway. The result was that area scoping silently switched itself off
        // for the entire ajax surface — dutyDenyWrite() returned null for every
        // ward, so a head scoped to one ward could write any other one's roster.
        //
        // Publishing our own handle first is what makes the fence real. It must
        // happen before the first area_scope_ids() call, which memoises.
        if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
            $GLOBALS['conn'] = $this->db;
        }
        if (function_exists('dept_scope_id')) return;
        $f = __DIR__ . '/dept-scope.php';
        if (is_file($f)) require_once $f;
    }

    /**
     * Area ids this session is pinned to for the roster, or [] when unscoped.
     * Area is finer than department — four nurse stations share one department,
     * and a head must not paint a neighbouring ward's sheet.
     */
    public function dutyAreaScope(): array
    {
        $this->loadScopeHelpers();
        return function_exists('area_scope_ids') ? area_scope_ids() : [];
    }

    /** May this session paint (not merely read) the roster for $area_id? */
    public function dutyCanEditArea(int $area_id): bool
    {
        $this->loadScopeHelpers();
        return function_exists('roster_can_edit_area') ? roster_can_edit_area($area_id) : true;
    }

    /**
     * Refuse a write that reaches outside the areas this session may paint.
     * Returns an error string, or null when every employee is in bounds.
     *
     * Read access and write access diverge here: a Section Head and a Supervisor
     * both SEE their ward's grid, but only the Department/Division Head paints
     * it. The UI already hides the palette from them; this is the half that a
     * hand-made POST cannot get around.
     */
    /**
     * The admin-only freeze switch: while set, every write on this screen is
     * refused for EVERY role, admin included — the point is a cutoff nobody
     * can touch until the admin deliberately unlocks it again, not a role
     * check the admin quietly bypasses.
     */
    private function dutyRosterLockDeny(): ?string
    {
        if ($this->pay_setting('duty_roster_locked', 0) < 1) return null;
        return 'The duty roster is locked by the administrator. Ask them to unlock it before making changes.';
    }

    /**
     * Append one entry to the duty roster's change history.
     *
     * employee_day_schedule's own created_by / changed_by describe the row as it
     * stands now, which cannot say who DELETED a day — the row and its audit
     * columns go together. So every write path calls this instead, and the log is
     * append-only: nothing here ever updates or removes an earlier entry.
     *
     * Best-effort by design. A history write must never be the reason a roster
     * save fails, so a missing table or a failed insert is swallowed; the caller
     * is already inside its own transaction and does not want ours.
     */
    private function dutyLog(string $period, string $action, string $detail, array $opt = []): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO duty_roster_log
                   (period, employee_id, work_date, action,
                    old_schedule_id, old_is_rest_day, new_schedule_id, new_is_rest_day,
                    was_published, note, detail, user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            if (!$stmt) return;

            $eid   = isset($opt['employee_id']) ? (int) $opt['employee_id'] : null;
            $date  = $opt['work_date']       ?? null;
            $os    = array_key_exists('old_schedule_id', $opt) && $opt['old_schedule_id'] !== null ? (int) $opt['old_schedule_id'] : null;
            $or    = array_key_exists('old_is_rest_day', $opt) && $opt['old_is_rest_day'] !== null ? (int) $opt['old_is_rest_day'] : null;
            $ns    = array_key_exists('new_schedule_id', $opt) && $opt['new_schedule_id'] !== null ? (int) $opt['new_schedule_id'] : null;
            $nr    = array_key_exists('new_is_rest_day', $opt) && $opt['new_is_rest_day'] !== null ? (int) $opt['new_is_rest_day'] : null;
            $pub   = !empty($opt['was_published']) ? 1 : 0;
            $note  = isset($opt['note']) && $opt['note'] !== '' ? mb_substr((string) $opt['note'], 0, 255) : null;
            $det   = mb_substr($detail, 0, 255);
            $uid   = (int) ($_SESSION['login_id'] ?? 0) ?: null;

            $stmt->bind_param(
                'sissiiiiissi',
                $period, $eid, $date, $action,
                $os, $or, $ns, $nr,
                $pub, $note, $det, $uid
            );
            $stmt->execute();
        } catch (\Throwable $e) {
            // History is an aid, not a gate — never let it break a roster write.
        }
    }

    /** "Nights (10:00 PM–6:00 AM)" / "Day off" / "blank", for log sentences. */
    private function dutyCellLabel(?int $scheduleId, ?int $isRestDay): string
    {
        if ($scheduleId === null && !$isRestDay) return 'blank';
        $parts = [];
        if ($scheduleId !== null) {
            if ($this->dutyShiftNames === null) {
                $this->dutyShiftNames = [];
                $q = $this->db->query("SELECT id, description FROM work_schedules");
                while ($q && ($r = $q->fetch_assoc())) $this->dutyShiftNames[(int) $r['id']] = (string) $r['description'];
            }
            $parts[] = $this->dutyShiftNames[$scheduleId] ?? ('shift #' . $scheduleId);
        }
        if ($isRestDay) $parts[] = 'day off';
        return implode(' + ', $parts);
    }

    private function dutyDenyWrite(array $empIds): ?string
    {
        $this->loadScopeHelpers();
        if (!function_exists('area_scope_ids') || area_scope_ids() === []) return null; // unscoped
        $empIds = array_values(array_filter(array_map('intval', $empIds)));
        if ($empIds === []) return null;

        $allowed = roster_editable_area_ids();
        if ($allowed === []) return 'You have view-only access to this roster.';

        $in = implode(',', $empIds);
        $ok = implode(',', array_map('intval', $allowed));
        $r  = $this->db->query("SELECT COUNT(*) c FROM employee
                                WHERE id IN ($in)
                                  AND (area_id IS NULL OR area_id NOT IN ($ok))");
        $out = $r ? (int) ($r->fetch_assoc()['c'] ?? 0) : 0;
        return $out > 0 ? 'That roster is outside the ward you may edit.' : null;
    }

    // Employees shown on the grid: everyone in the chosen department, PLUS
    // anyone who already has a row in this cutoff. The second half matters —
    // a nurse transferred out mid-cutoff still has duties on this sheet, and
    // dropping them from the grid would hide days nobody can then correct.
    // $areaId narrows further, inside whichever department/scope the caller
    // already resolved — a view-only refinement for the roster's optional
    // Area filter, never a way to widen past a session's area scope (it is
    // ANDed onto $fence below, alongside whatever that already restricts).
    public function dutyRosterEmployees($department_id, $from, $to, $areaId = 0): array
    {
        $dept = (int) $department_id;
        $areaId = (int) $areaId;
        $fromE = $this->db->real_escape_string($from);
        $toE   = $this->db->real_escape_string($to);

        // A Department Head / Supervisor is pinned to their own ward, and the
        // pin is applied HERE rather than at each caller. This one method feeds
        // the grid, the Excel export and the importer, so a caller that forgot
        // would be a silent leak of another ward's roster; there is nowhere else
        // to forget it.
        $scope = $this->dutyScopeId();
        if ($scope > 0) $dept = $scope;

        // Area beats department when the session has one: four nurse stations
        // live in the same department, so a department fence would hand a head
        // three neighbouring wards.
        $areas = $this->dutyAreaScope();

        // 0 = every department. Resigned staff are excluded from the pool, but
        // the "already has a row" clause below still surfaces anyone who left
        // mid-cutoff — their duties are on this sheet and must stay correctable.
        if ($areas !== []) {
            $in    = implode(',', array_map('intval', $areas));
            $where = "(e.area_id IN ($in) AND e.status = 1)";
        } else {
            $where = $dept > 0 ? "(e.department_id = $dept AND e.status = 1)" : "e.status = 1";
        }
        // The "OR they already have a row" arm below is deliberately NOT limited
        // by department — that is how a nurse transferred out mid-cutoff stays
        // correctable. For a scoped viewer that is a hole: it would hand them
        // every ward's transfers. So the whole condition is fenced instead of
        // the first arm only.
        if ($areas !== []) {
            $fence = ' AND e.area_id IN (' . implode(',', array_map('intval', $areas)) . ')';
        } else {
            $fence = $scope > 0 ? " AND e.department_id = $scope" : '';
        }
        if ($areaId > 0) $fence .= " AND e.area_id = $areaId";
        // The fixed shift is a correlated subquery rather than a join + GROUP BY:
        // several periods can overlap one cutoff, and grouping to collapse them
        // would select columns that ONLY_FULL_GROUP_BY rejects on a stricter
        // server than this one. LIMIT 1 also makes "which period" explicit —
        // the latest one that has started — instead of whatever the group kept.
        $res = $this->db->query("
            SELECT e.id, e.employee_no, e.firstname, e.lastname, e.middlename, e.status,
                   d.name AS dept_name, p.name AS pname,
                   (SELECT ws.description
                      FROM employee_schedules es
                      INNER JOIN work_schedules ws ON ws.id = es.schedule_id
                     WHERE es.employee_id = e.id
                       AND es.effective_from <= '$toE'
                       AND (es.effective_to IS NULL OR es.effective_to >= '$fromE')
                     ORDER BY es.effective_from DESC LIMIT 1) AS period_shift
            FROM employee e
            LEFT JOIN department d ON d.id = e.department_id
            LEFT JOIN position p ON p.id = e.position_id
            WHERE ($where
                   OR e.id IN (SELECT employee_id FROM employee_day_schedule
                               WHERE work_date BETWEEN '$fromE' AND '$toE'))
                  $fence
            ORDER BY e.lastname ASC, e.firstname ASC
        ");
        $out = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = [
                'id'           => (int) $r['id'],
                'employee_no'  => (string) $r['employee_no'],
                'name'         => trim($r['lastname'] . ', ' . $r['firstname']),
                'dept'         => (string) ($r['dept_name'] ?? ''),
                'position'     => (string) ($r['pname'] ?? ''),
                'period_shift' => (string) ($r['period_shift'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Areas (wards) inside one department, for the roster's optional Area
     * filter — a department can hold several wards, and this is the piece
     * that lets an unscoped viewer narrow the grid to one instead of always
     * seeing the whole department at once. Read-only; area_scope_ids() is
     * what actually restricts a scoped session, not this list.
     */
    function duty_roster_areas()
    {
        $dept = (int) ($_POST['department_id'] ?? 0);
        if (!$dept) return ['result' => true, 'areas' => []];
        $stmt = $this->db->prepare("SELECT id, name FROM area WHERE department_id = ? AND status = 1 ORDER BY name ASC");
        $stmt->bind_param('i', $dept);
        $stmt->execute();
        $res  = $stmt->get_result();
        $out  = [];
        while ($r = $res->fetch_assoc()) $out[] = ['id' => (int) $r['id'], 'name' => $r['name']];
        return ['result' => true, 'areas' => $out];
    }

    /**
     * The active shifts with the SHORT CODE each one is written as in Excel.
     *
     * "NOC / 11-7 (11PM-7AM)" → "NOC". Mirrors shortLabel() in
     * assets2/js/duty-roster.js so the sheet, the screen and the importer all
     * say the same word. A collision is disambiguated with the id rather than
     * left to silently mean two shifts — the code is what gets typed back in.
     *
     * Export and import BOTH read this. They used to build the map separately,
     * which is a class of bug that only shows up after someone renames a shift.
     */
    public function dutyShiftCodes(): array
    {
        $used = [];
        $out  = [];
        $sq = $this->db->query("SELECT id, description, start_time, end_time, total_hours, is_graveyard
                                FROM work_schedules WHERE status = 1 ORDER BY start_time ASC");
        while ($sq && ($s = $sq->fetch_assoc())) {
            $code = trim(explode('/', explode('(', $s['description'])[0])[0]);
            $code = mb_substr($code, 0, 5) ?: ('S' . $s['id']);
            if (isset($used[$code])) $code = $code . '-' . $s['id'];
            $used[$code] = true;
            $out[] = [
                'id'    => (int) $s['id'],
                'code'  => $code,
                'desc'  => (string) $s['description'],
                'start' => date('g:i A', strtotime($s['start_time'])),
                'end'   => date('g:i A', strtotime($s['end_time'])),
                'hours' => (float) $s['total_hours'],
                'noc'   => (int) $s['is_graveyard'],
            ];
        }
        return $out;
    }

    /**
     * Approved leave, expanded to the actual DAYS, keyed "employee_id|Y-m-d".
     *
     * The expansion is the whole point. A leave row carries date_from/date_to,
     * but `dates` holds the days actually taken, and the two are not the same
     * thing: a real row in this database spans Jul 29 – Aug 27 and is THREE
     * days — the 29th, the 24th and the 27th. Reading the range would flag
     * thirty. A conflict warning that cries wolf thirty times gets switched off
     * in a week, so this reads `dates` first and only falls back to the range
     * when it is empty.
     *
     * Unpaid leave is included, unlike the payroll tally which only wants paid:
     * being rostered while on LWOP is still someone who will not be on the ward.
     */
    public function dutyLeaveMap($from, $to, array $empIds): array
    {
        $out = [];
        if (empty($empIds)) return $out;
        $ids = implode(',', array_map('intval', $empIds));
        $res = $this->db->query(
            "SELECT lr.employee_id, lr.dates, lr.date_from, lr.date_to,
                    lr.is_half_day, lr.half_date, lr.half_period,
                    COALESCE(lt.name, 'Leave') AS type_name
             FROM leave_requests lr
             LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
             WHERE lr.status = 1
               AND lr.employee_id IN ($ids)
               AND lr.date_from <= '" . $this->db->real_escape_string($to) . "'
               AND lr.date_to   >= '" . $this->db->real_escape_string($from) . "'"
        );
        while ($res && ($lv = $res->fetch_assoc())) {
            $days = [];
            if (!empty($lv['dates'])) {
                $decoded = json_decode($lv['dates'], true);
                if (is_array($decoded)) $days = $decoded;
            }
            if (!$days) {
                for ($d = strtotime($lv['date_from']); $d <= strtotime($lv['date_to']); $d = strtotime('+1 day', $d)) {
                    $days[] = date('Y-m-d', $d);
                }
            }
            foreach ($days as $dy) {
                $ymd = date('Y-m-d', strtotime($dy));
                if ($ymd < $from || $ymd > $to) continue;
                $half = ((int) $lv['is_half_day'] === 1 && !empty($lv['half_date'])
                         && date('Y-m-d', strtotime($lv['half_date'])) === $ymd);
                $k = $lv['employee_id'] . '|' . $ymd;
                // A full day beats a half day when two leaves land on one date.
                if (isset($out[$k]) && !$out[$k]['half']) continue;
                $out[$k] = [
                    'name' => (string) $lv['type_name'],
                    'half' => $half ? 1 : 0,
                    'part' => $half ? (string) ($lv['half_period'] ?? '') : '',
                ];
            }
        }
        return $out;
    }

    /** Everything the grid needs for one cutoff, in one request. */
    function duty_roster_data()
    {
        $range = $this->dutyPeriodRange($_POST['period'] ?? '');
        if (!$range) return ['result' => false, 'message' => 'Invalid cutoff period.'];
        $dept   = (int) ($_POST['department_id'] ?? 0);
        $areaId = (int) ($_POST['area_id'] ?? 0);

        $employees = $this->dutyRosterEmployees($dept, $range['from'], $range['to'], $areaId);
        $empIds    = array_column($employees, 'id');

        $days = [];
        for ($t = strtotime($range['from']); $t <= strtotime($range['to']); $t = strtotime('+1 day', $t)) {
            $days[] = ['date' => date('Y-m-d', $t), 'dom' => (int) date('j', $t), 'dow' => date('D', $t), 'w' => (int) date('w', $t)];
        }

        // Holidays colour the header the same way the DTR sheets do.
        $holidays = [];
        $hq = $this->db->query("SELECT start_date, end_date, type, title FROM calendar_events
            WHERE type IN (1,3) AND start_date <= '" . $this->db->real_escape_string($range['to']) . "'
              AND COALESCE(end_date, start_date) >= '" . $this->db->real_escape_string($range['from']) . "'");
        while ($hq && ($h = $hq->fetch_assoc())) {
            $end = $h['end_date'] ?: $h['start_date'];
            for ($t = strtotime($h['start_date']); $t <= strtotime($end); $t = strtotime('+1 day', $t)) {
                $holidays[date('Y-m-d', $t)] = ['type' => (int) $h['type'], 'title' => (string) $h['title']];
            }
        }

        $shifts = [];
        $sq = $this->db->query("SELECT id, description, start_time, end_time, total_hours, is_graveyard
                                FROM work_schedules WHERE status = 1 ORDER BY start_time ASC");
        while ($sq && ($s = $sq->fetch_assoc())) {
            $shifts[] = [
                'id'    => (int) $s['id'],
                'desc'  => (string) $s['description'],
                'start' => date('g:i A', strtotime($s['start_time'])),
                'end'   => date('g:i A', strtotime($s['end_time'])),
                'hours' => (float) $s['total_hours'],
                'noc'   => (int) $s['is_graveyard'],
            ];
        }

        $cells = [];
        if ($empIds) {
            $ids = implode(',', array_map('intval', $empIds));
            $cq = $this->db->query("
                SELECT eds.employee_id, eds.work_date, eds.schedule_id, eds.is_rest_day, eds.status,
                       eds.planned_schedule_id, eds.planned_is_rest_day, eds.note, eds.changed_at,
                       u.name AS changed_by_name
                FROM employee_day_schedule eds
                LEFT JOIN users u ON u.id = eds.changed_by
                WHERE eds.employee_id IN ($ids)
                  AND eds.work_date BETWEEN '" . $this->db->real_escape_string($range['from']) . "'
                                        AND '" . $this->db->real_escape_string($range['to']) . "'");
            while ($cq && ($c = $cq->fetch_assoc())) {
                $planned = $c['planned_schedule_id'] !== null || $c['planned_is_rest_day'] !== null;
                $cells[$c['employee_id'] . '|' . $c['work_date']] = [
                    's'  => $c['schedule_id'] !== null ? (int) $c['schedule_id'] : null,
                    'r'  => (int) $c['is_rest_day'],
                    'st' => (int) $c['status'],
                    // Only surfaced when it actually differs — that is the swap
                    // the head nurse will be asked about later.
                    'ps' => $planned ? ($c['planned_schedule_id'] !== null ? (int) $c['planned_schedule_id'] : null) : null,
                    'pr' => $planned ? (int) $c['planned_is_rest_day'] : null,
                    'by' => trim((string) ($c['changed_by_name'] ?? '')),
                    'at' => $c['changed_at'] ? date('M j, g:i A', strtotime($c['changed_at'])) : '',
                    'n'  => (string) ($c['note'] ?? ''),
                ];
            }
        }

        // Which OTHER cutoffs these same employees have days in. Planning runs
        // ahead of the calendar, so opening on an empty cutoff is normal and
        // looks identical to losing the work — this lets the UI answer "your
        // roster is over there" instead of showing a blank grid.
        $other = [];
        if ($empIds) {
            $ids = implode(',', array_map('intval', $empIds));
            $oq = $this->db->query("
                SELECT CONCAT(YEAR(work_date), '-', LPAD(MONTH(work_date), 2, '0'), '-',
                              IF(DAY(work_date) <= 15, '1', '2')) AS period,
                       COUNT(*) AS n, SUM(status = 1) AS published, MIN(work_date) AS first_day
                FROM employee_day_schedule
                WHERE employee_id IN ($ids)
                  AND work_date NOT BETWEEN '" . $this->db->real_escape_string($range['from']) . "'
                                        AND '" . $this->db->real_escape_string($range['to']) . "'
                GROUP BY period
                ORDER BY first_day DESC
                LIMIT 6");
            while ($oq && ($o = $oq->fetch_assoc())) {
                $ts = strtotime($o['first_day']);
                $half = ((int) date('j', $ts) <= 15) ? 1 : 2;
                $last = (int) date('t', $ts);
                $other[] = [
                    'period'    => (string) $o['period'],
                    'label'     => $half === 1
                        ? date('M 1', $ts) . ' – ' . date('M 15, Y', $ts)
                        : date('M 16', $ts) . ' – ' . date("M $last, Y", $ts),
                    'days'      => (int) $o['n'],
                    'published' => (int) $o['published'],
                ];
            }
        }

        return [
            'result'    => true,
            'from'      => $range['from'],
            'to'        => $range['to'],
            'days'      => $days,
            'holidays'  => $holidays,
            'shifts'    => $shifts,
            'employees' => $employees,
            'cells'     => (object) $cells,
            'zones'     => (object) $this->dutyZoneMap($range['from'], $range['to'], $empIds),
            'leaves'    => (object) $this->dutyLeaveMap($range['from'], $range['to'], $empIds),
            'other'     => $other,
        ];
    }

    /**
     * Write painted cells.
     *
     * A cell with neither a shift nor a rest flag is DELETED, not stored blank:
     * "not on the day grid" is a real answer that hands the date back to the
     * period roster, and a blank row would instead force a rest-day-with-no-
     * shift reading.
     *
     * status is left alone on rows that already exist. A new cell therefore
     * stays a draft until Publish, while an edit to an already-published day
     * takes effect immediately — which is what a mid-cutoff swap has to do.
     * The response says how many of those touched days already carry
     * attendance, so the UI can offer the Recompute they now need.
     */
    function duty_roster_save()
    {
        if ($deny = $this->dutyRosterLockDeny()) return ['result' => false, 'message' => $deny];
        $range = $this->dutyPeriodRange($_POST['period'] ?? '');
        if (!$range) return ['result' => false, 'message' => 'Invalid cutoff period.'];

        $cells = json_decode((string) ($_POST['cells'] ?? '[]'), true);
        if (!is_array($cells) || !$cells) return ['result' => false, 'message' => 'Nothing to save.'];
        if (count($cells) > 5000) return ['result' => false, 'message' => 'Too many cells in one save.'];

        $uid   = (int) ($_SESSION['login_id'] ?? 0) ?: null;
        $empIds = [];
        foreach ($cells as $c) if (!empty($c['e'])) $empIds[(int) $c['e']] = true;

        if ($deny = $this->dutyDenyWrite(array_keys($empIds))) {
            return ['result' => false, 'message' => $deny];
        }

        $zones = $this->dutyZoneMap($range['from'], $range['to'], array_keys($empIds));

        $validShifts = [];
        $vq = $this->db->query("SELECT id FROM work_schedules WHERE status = 1");
        while ($vq && ($v = $vq->fetch_assoc())) $validShifts[(int) $v['id']] = true;

        $ins = $this->db->prepare(
            "INSERT INTO employee_day_schedule (employee_id, work_date, schedule_id, is_rest_day, note, created_by, changed_by)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE schedule_id = VALUES(schedule_id),
                                     is_rest_day = VALUES(is_rest_day),
                                     note        = VALUES(note),
                                     changed_by  = VALUES(changed_by)"
        );
        $del = $this->db->prepare("DELETE FROM employee_day_schedule WHERE employee_id = ? AND work_date = ?");

        // What these cells look like BEFORE the write, so the history can say
        // "Nights → Day off" rather than just "changed". Read in one query over
        // the whole batch — a paint drag can be hundreds of cells and this must
        // not become a per-cell round trip.
        $before = [];
        if ($empIds) {
            $bq = $this->db->query(
                "SELECT employee_id, work_date, schedule_id, is_rest_day, status
                 FROM employee_day_schedule
                 WHERE employee_id IN (" . implode(',', array_map('intval', array_keys($empIds))) . ")
                   AND work_date BETWEEN '" . $this->db->real_escape_string($range['from']) . "'
                                     AND '" . $this->db->real_escape_string($range['to']) . "'"
            );
            while ($bq && ($b = $bq->fetch_assoc())) {
                $before[$b['employee_id'] . '|' . $b['work_date']] = $b;
            }
        }
        $period = (string) ($_POST['period'] ?? '');
        $logs   = [];   // written after the transaction commits — see below

        // Three screens post here: the grid, the spreadsheet import (which is
        // preview-only and applies through this same endpoint), and the Daily
        // Board's one-cell adjust. The history has to say which, or an imported
        // sheet is indistinguishable from someone painting 300 cells by hand.
        $SOURCES = ['grid' => '', 'import' => ' (spreadsheet import)', 'board' => ' (Daily Board)'];
        $srcKey  = (string) ($_POST['source'] ?? 'grid');
        $srcTag  = $SOURCES[$srcKey] ?? '';

        $saved = $cleared = $locked = $invalid = 0;
        $needsRecompute = [];

        $this->db->begin_transaction();
        try {
            foreach ($cells as $c) {
                $eid  = (int) ($c['e'] ?? 0);
                $date = (string) ($c['d'] ?? '');
                if (!$eid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $invalid++; continue; }
                if ($date < $range['from'] || $date > $range['to']) { $invalid++; continue; }

                $zone = $zones[$eid . '|' . $date] ?? 'free';
                if ($zone === 'locked') { $locked++; continue; }

                $sid  = isset($c['s']) && $c['s'] !== null && $c['s'] !== '' ? (int) $c['s'] : null;
                $rest = !empty($c['r']) ? 1 : 0;
                if ($sid !== null && !isset($validShifts[$sid])) { $invalid++; continue; }

                $prev    = $before[$eid . '|' . $date] ?? null;
                $wasPub  = $prev ? ((int) $prev['status'] === 1) : false;
                $oldSid  = $prev && $prev['schedule_id'] !== null ? (int) $prev['schedule_id'] : null;
                $oldRest = $prev ? (int) $prev['is_rest_day'] : null;

                if ($sid === null && !$rest) {
                    $del->bind_param('is', $eid, $date);
                    if (!$del->execute()) throw new Exception($del->error);
                    if ($del->affected_rows > 0) {
                        $cleared++;
                        $logs[] = ['delete', 'Cleared ' . $this->dutyCellLabel($oldSid, $oldRest), [
                            'employee_id' => $eid, 'work_date' => $date,
                            'old_schedule_id' => $oldSid, 'old_is_rest_day' => $oldRest,
                            'was_published' => $wasPub,
                        ]];
                    }
                    if ($del->affected_rows > 0 && $zone === 'punched') $needsRecompute[$eid] = true;
                    continue;
                }

                $note = mb_substr(trim((string) ($c['n'] ?? '')), 0, 255);
                $ins->bind_param('isiisii', $eid, $date, $sid, $rest, $note, $uid, $uid);
                if (!$ins->execute()) throw new Exception($ins->error);
                $saved++;
                // A cell that already said the same thing is not a change worth a
                // timeline entry — a row-fill or column-fill repaints everything
                // it touches, and logging the no-ops would bury the real edits.
                if (!$prev) {
                    $logs[] = ['create', 'Set to ' . $this->dutyCellLabel($sid, $rest), [
                        'employee_id' => $eid, 'work_date' => $date,
                        'new_schedule_id' => $sid, 'new_is_rest_day' => $rest,
                        'note' => $note,
                    ]];
                } elseif ($oldSid !== $sid || (int) $oldRest !== $rest) {
                    $logs[] = ['update', $this->dutyCellLabel($oldSid, $oldRest) . ' → ' . $this->dutyCellLabel($sid, $rest), [
                        'employee_id' => $eid, 'work_date' => $date,
                        'old_schedule_id' => $oldSid, 'old_is_rest_day' => $oldRest,
                        'new_schedule_id' => $sid, 'new_is_rest_day' => $rest,
                        'was_published' => $wasPub, 'note' => $note,
                    ]];
                }
                if ($zone === 'punched') $needsRecompute[$eid] = true;
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Save failed — nothing was changed. ' . $e->getMessage()];
        }

        // Only after the commit: a rolled-back save must leave no history behind,
        // and dutyLog() writes outside the caller's transaction on purpose.
        foreach ($logs as [$action, $detail, $opt]) $this->dutyLog($period, $action, $detail . $srcTag, $opt);

        $msg = [];
        if ($saved)   $msg[] = "$saved day(s) saved";
        if ($cleared) $msg[] = "$cleared cleared";
        if ($locked)  $msg[] = "$locked skipped (approved DTR — locked)";
        if ($invalid) $msg[] = "$invalid skipped (invalid)";

        return [
            'result'          => true,
            'saved'           => $saved,
            'cleared'         => $cleared,
            'locked'          => $locked,
            'needs_recompute' => array_keys($needsRecompute),
            'message'         => $msg ? implode(', ', $msg) . '.' : 'No changes.',
        ];
    }

    /**
     * Make this cutoff's drafts real. Publishing is the only act that lets a
     * rostered day reach DTR figures or the employee portal, so it is also
     * where employees are told what they are working.
     *
     * planned_* is filled on FIRST publish only (COALESCE), preserving what was
     * originally handed out even after a swap rewrites schedule_id.
     */
    function duty_roster_publish()
    {
        if ($deny = $this->dutyRosterLockDeny()) return ['result' => false, 'message' => $deny];
        $range = $this->dutyPeriodRange($_POST['period'] ?? '');
        if (!$range) return ['result' => false, 'message' => 'Invalid cutoff period.'];
        $dept = (int) ($_POST['department_id'] ?? 0);

        $employees = $this->dutyRosterEmployees($dept, $range['from'], $range['to']);
        $empIds    = array_column($employees, 'id');
        if (!$empIds) return ['result' => false, 'message' => 'No employees in this view.'];
        if ($deny = $this->dutyDenyWrite($empIds)) return ['result' => false, 'message' => $deny];
        $ids   = implode(',', array_map('intval', $empIds));
        $fromE = $this->db->real_escape_string($range['from']);
        $toE   = $this->db->real_escape_string($range['to']);

        // Who gets a notification — read BEFORE the update, while the drafts
        // are still identifiable as drafts.
        $affected = [];
        $aq = $this->db->query("SELECT employee_id, COUNT(*) AS n FROM employee_day_schedule
            WHERE status = 0 AND employee_id IN ($ids) AND work_date BETWEEN '$fromE' AND '$toE'
            GROUP BY employee_id");
        while ($aq && ($a = $aq->fetch_assoc())) $affected[(int) $a['employee_id']] = (int) $a['n'];
        if (!$affected) return ['result' => false, 'message' => 'Nothing to publish — no draft days in this cutoff.'];

        /**
         * Someone rostered on a day they have APPROVED leave.
         *
         * Publishing is where this has to be caught, because publishing is what
         * tells the employee they are working — and a nurse who is on approved
         * leave that day will not come in. The day then reads as an absence
         * against a shift they were never going to work, and it surfaces weeks
         * later in payroll where it costs money to unpick.
         *
         * Reported, not blocked: a cancelled leave or an agreed swap is a real
         * reason to roster over one, and the planner is the one who knows. The
         * client asks for confirmation on the way past.
         */
        $conflicts = [];
        $leaves = $this->dutyLeaveMap($range['from'], $range['to'], array_keys($affected));
        if ($leaves) {
            $names = [];
            foreach ($employees as $e) $names[$e['id']] = $e['name'];
            $dq = $this->db->query("SELECT employee_id, work_date, is_rest_day
                FROM employee_day_schedule
                WHERE status = 0 AND employee_id IN ($ids) AND work_date BETWEEN '$fromE' AND '$toE'");
            while ($dq && ($d = $dq->fetch_assoc())) {
                // A rest day on a leave day is not a conflict — it agrees with it.
                if ((int) $d['is_rest_day'] === 1) continue;
                $k = $d['employee_id'] . '|' . $d['work_date'];
                if (!isset($leaves[$k])) continue;
                $conflicts[] = [
                    'employee' => $names[(int) $d['employee_id']] ?? ('#' . $d['employee_id']),
                    'date'     => date('M j', strtotime($d['work_date'])),
                    'leave'    => $leaves[$k]['name'] . ($leaves[$k]['half'] ? ' (half day)' : ''),
                ];
            }
        }
        // The client re-sends with confirm_leave=1 once the planner has read them.
        if ($conflicts && empty($_POST['confirm_leave'])) {
            return [
                'result'    => false,
                'conflicts' => array_slice($conflicts, 0, 25),
                'conflict_total' => count($conflicts),
                'message'   => count($conflicts) . ' day(s) are rostered on approved leave.',
            ];
        }

        $ok = $this->db->query("UPDATE employee_day_schedule
            SET status = 1,
                published_at = NOW(),
                planned_schedule_id = COALESCE(planned_schedule_id, schedule_id),
                planned_is_rest_day = COALESCE(planned_is_rest_day, is_rest_day)
            WHERE status = 0 AND employee_id IN ($ids) AND work_date BETWEEN '$fromE' AND '$toE'");
        if (!$ok) return ['result' => false, 'message' => 'Publish failed: ' . $this->db->error];

        $this->dutyLog((string) ($_POST['period'] ?? ''), 'publish',
            array_sum($affected) . ' day(s) published for ' . count($affected) . ' employee(s)'
            . ($conflicts ? ', ' . count($conflicts) . ' on approved leave (confirmed)' : ''));

        $label = date('M j', strtotime($range['from'])) . ' – ' . date('M j, Y', strtotime($range['to']));
        foreach ($affected as $eid => $n) {
            $this->notifyEmployee(
                $eid,
                'Your duty schedule is out',
                "Your duty schedule for $label has been published ($n day(s)). Please check your shifts and rest days.",
                'ri-calendar-check-line',
                'info',
                'employee-portal.php?tab=info'
            );
        }

        // Days already punched under the OLD shift need a recompute before the
        // batch can be approved — same rule the stale-schedule banner enforces.
        $zones = $this->dutyZoneMap($range['from'], $range['to'], array_keys($affected));
        $needs = [];
        foreach ($zones as $k => $z) {
            if ($z !== 'punched') continue;
            [$eid, $d] = explode('|', $k);
            if (isset($affected[(int) $eid])) $needs[(int) $eid] = true;
        }

        return [
            'result'          => true,
            'published'       => array_sum($affected),
            'employees'       => count($affected),
            'needs_recompute' => array_keys($needs),
            'leave_conflicts' => count($conflicts),
            'message'         => array_sum($affected) . ' day(s) published for ' . count($affected) . ' employee(s). They have been notified.'
                                 . ($conflicts ? ' ' . count($conflicts) . ' were on approved leave — you confirmed these.' : ''),
        ];
    }

    /**
     * Seed this cutoff from the previous one, as drafts.
     *
     * Days are mapped by POSITION, not by weekday: a rotation is a cycle, so
     * day 1 of this cutoff continues from day 1 of the last. Cutoffs differ in
     * length (15 vs 13-16 days), so the source wraps — which keeps the cycle
     * running instead of leaving the tail blank.
     *
     * Existing rows are never touched: published days stay as handed out, and
     * locked days are refused outright.
     */
    function duty_roster_copy()
    {
        if ($deny = $this->dutyRosterLockDeny()) return ['result' => false, 'message' => $deny];
        $range = $this->dutyPeriodRange($_POST['period'] ?? '');
        $prev  = $this->dutyPrevPeriod($_POST['period'] ?? '');
        $prevRange = $prev ? $this->dutyPeriodRange($prev) : null;
        if (!$range || !$prevRange) return ['result' => false, 'message' => 'Invalid cutoff period.'];

        $dept      = (int) ($_POST['department_id'] ?? 0);
        $employees = $this->dutyRosterEmployees($dept, $range['from'], $range['to']);
        $empIds    = array_column($employees, 'id');
        if (!$empIds) return ['result' => false, 'message' => 'No employees in this view.'];
        if ($deny = $this->dutyDenyWrite($empIds)) return ['result' => false, 'message' => $deny];
        $ids = implode(',', array_map('intval', $empIds));

        $srcDays = [];
        for ($t = strtotime($prevRange['from']); $t <= strtotime($prevRange['to']); $t = strtotime('+1 day', $t)) $srcDays[] = date('Y-m-d', $t);
        $dstDays = [];
        for ($t = strtotime($range['from']); $t <= strtotime($range['to']); $t = strtotime('+1 day', $t)) $dstDays[] = date('Y-m-d', $t);

        $src = [];
        $sq = $this->db->query("SELECT employee_id, work_date, schedule_id, is_rest_day
            FROM employee_day_schedule
            WHERE employee_id IN ($ids)
              AND work_date BETWEEN '" . $this->db->real_escape_string($prevRange['from']) . "'
                                AND '" . $this->db->real_escape_string($prevRange['to']) . "'");
        while ($sq && ($s = $sq->fetch_assoc())) {
            $src[(int) $s['employee_id']][$s['work_date']] = [
                's' => $s['schedule_id'] !== null ? (int) $s['schedule_id'] : null,
                'r' => (int) $s['is_rest_day'],
            ];
        }
        if (!$src) return ['result' => false, 'message' => 'The previous cutoff has no roster to copy.'];

        $existing = [];
        $eq = $this->db->query("SELECT employee_id, work_date FROM employee_day_schedule
            WHERE employee_id IN ($ids)
              AND work_date BETWEEN '" . $this->db->real_escape_string($range['from']) . "'
                                AND '" . $this->db->real_escape_string($range['to']) . "'");
        while ($eq && ($e = $eq->fetch_assoc())) $existing[$e['employee_id'] . '|' . $e['work_date']] = true;

        $zones = $this->dutyZoneMap($range['from'], $range['to'], $empIds);
        $uid   = (int) ($_SESSION['login_id'] ?? 0) ?: null;

        $ins = $this->db->prepare(
            "INSERT INTO employee_day_schedule (employee_id, work_date, schedule_id, is_rest_day, created_by, changed_by)
             VALUES (?,?,?,?,?,?)"
        );
        $copied = $skipped = 0;

        $this->db->begin_transaction();
        try {
            foreach ($empIds as $eid) {
                if (empty($src[$eid])) continue;
                foreach ($dstDays as $i => $dst) {
                    if (isset($existing[$eid . '|' . $dst])) { $skipped++; continue; }
                    if (($zones[$eid . '|' . $dst] ?? 'free') === 'locked') { $skipped++; continue; }
                    $srcDate = $srcDays[$i % count($srcDays)];
                    if (!isset($src[$eid][$srcDate])) continue;
                    // bind_param takes references, so the cell is unpacked into
                    // plain locals rather than passing array elements.
                    $cSid  = $src[$eid][$srcDate]['s'];
                    $cRest = $src[$eid][$srcDate]['r'];
                    $ins->bind_param('isiiii', $eid, $dst, $cSid, $cRest, $uid, $uid);
                    if (!$ins->execute()) throw new Exception($ins->error);
                    $copied++;
                }
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Copy failed — nothing was changed. ' . $e->getMessage()];
        }

        $this->dutyLog((string) ($_POST['period'] ?? ''), 'copy',
            "$copied day(s) seeded as drafts from " . $this->dutyPeriodLabel($prev)
            . ($skipped ? ", $skipped left alone" : ''));

        return [
            'result'  => true,
            'copied'  => $copied,
            'skipped' => $skipped,
            'message' => "$copied day(s) copied as drafts" . ($skipped ? ", $skipped left alone (already set or locked)" : '') . '. Review, then Publish.',
        ];
    }

    /** Discard this cutoff's unpublished drafts. Published days are untouched. */
    function duty_roster_clear_drafts()
    {
        if ($deny = $this->dutyRosterLockDeny()) return ['result' => false, 'message' => $deny];
        $range = $this->dutyPeriodRange($_POST['period'] ?? '');
        if (!$range) return ['result' => false, 'message' => 'Invalid cutoff period.'];
        $dept      = (int) ($_POST['department_id'] ?? 0);
        $employees = $this->dutyRosterEmployees($dept, $range['from'], $range['to']);
        $empIds    = array_column($employees, 'id');
        if (!$empIds) return ['result' => false, 'message' => 'No employees in this view.'];
        if ($deny = $this->dutyDenyWrite($empIds)) return ['result' => false, 'message' => $deny];
        $ids = implode(',', array_map('intval', $empIds));

        $ok = $this->db->query("DELETE FROM employee_day_schedule
            WHERE status = 0 AND employee_id IN ($ids)
              AND work_date BETWEEN '" . $this->db->real_escape_string($range['from']) . "'
                                AND '" . $this->db->real_escape_string($range['to']) . "'");
        if (!$ok) return ['result' => false, 'message' => 'Clear failed: ' . $this->db->error];

        $n = $this->db->affected_rows;
        // Worth logging even at zero: "someone pressed Discard and nothing was
        // there" is a different story from "nobody touched it".
        $this->dutyLog((string) ($_POST['period'] ?? ''), 'discard',
            "$n draft day(s) discarded across " . count($empIds) . ' employee(s) in view');
        return ['result' => true, 'deleted' => $n, 'message' => "$n draft day(s) discarded."];
    }

    /**
     * Apply roster changes to attendance already recorded in this cutoff.
     *
     * Scoped to the cutoff's dates and to open batches — the same force-mode
     * recompute the DTR page runs, just narrowed so a head nurse fixing one
     * cutoff never restates another. Locked batches are excluded by the
     * b.status <> 2 filter, which is also what makes the roster's "locked"
     * cells honest.
     */
    function duty_roster_recompute()
    {
        if ($deny = $this->dutyRosterLockDeny()) return ['result' => false, 'message' => $deny];
        $range = $this->dutyPeriodRange($_POST['period'] ?? '');
        if (!$range) return ['result' => false, 'message' => 'Invalid cutoff period.'];

        $ids = [];
        $raw = $_POST['employee_ids'] ?? '';
        if (!is_array($raw)) $raw = explode(',', (string) $raw);
        foreach ($raw as $v) if ((int) $v) $ids[] = (int) $v;
        $ids = array_values(array_unique($ids));
        if (!$ids) return ['result' => false, 'message' => 'No employees to recompute.'];
        // Employee ids arrive straight from the POST body here, so this guard is
        // the only thing standing between a view-only head and another ward.
        if ($deny = $this->dutyDenyWrite($ids)) return ['result' => false, 'message' => $deny];
        $idList = implode(',', $ids);

        $res = $this->db->query("SELECT d.id, d.employee_id, d.date_time, d.work_hours, d.overtime, d.undertime,
                                        d.late, d.nsd_hours, d.day_type, d.status, d.logs,
                                        d.schedule_id, d.day_hours, d.is_rest_day,
                                        d.sched_start, d.sched_end, d.sched_break, d.sched_graveyard
                                 FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
                                 WHERE d.employee_id IN ($idList) AND b.status <> 2
                                   AND DATE(d.date_time) BETWEEN '" . $this->db->real_escape_string($range['from']) . "'
                                                             AND '" . $this->db->real_escape_string($range['to']) . "'");

        $this->db->begin_transaction();
        try {
            [$scanned, $changed, $repending, $affectedEmp] = $this->recomputeDetailRows($res);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Recompute failed — nothing was changed. ' . $e->getMessage()];
        }

        foreach ($affectedEmp as $eid => $n) {
            $this->logRecompute(null, $eid, 'duty-roster', $scanned, $changed, $repending);
            $this->notifyEmployee(
                $eid,
                'Attendance recalculated',
                "$n day(s) of your attendance were recalculated after a duty-roster change. Please review them in your portal.",
                'ri-refresh-line',
                'info',
                'employee-portal.php?tab=attendance'
            );
        }

        $this->dutyLog((string) ($_POST['period'] ?? ''), 'recompute',
            'Recomputed attendance for ' . count($ids) . ' employee(s)'
            . " — $scanned scanned, $changed updated, $repending back to pending");

        return [
            'result'    => true,
            'scanned'   => $scanned,
            'changed'   => $changed,
            'repending' => $repending,
            'message'   => "$scanned record(s) scanned, $changed updated, $repending sent back to pending.",
        ];
    }

    /**
     * Read a filled-in duty-roster workbook back and say what it WOULD change.
     *
     * This writes NOTHING. It returns the list of changed cells, and the page
     * loads them into the same pending-edit map a click would have filled, so
     * the planner sees them painted on the grid and still has to press Save and
     * then Publish. That keeps one write path (duty_roster_save) with one set of
     * lock rules, instead of a second door into the table that has to remember
     * the same rules — and an upload that silently rewrote a published cutoff is
     * exactly the accident worth designing out.
     *
     * The sheet is read by its HEADERS, not by fixed coordinates: the "Employee
     * No" cell is searched for, and the day columns are taken from the numbers
     * on that row. Someone will insert a column or sort the rows, and the file
     * should still import.
     */
    function duty_roster_import()
    {
        if ($deny = $this->dutyRosterLockDeny()) return ['result' => false, 'message' => $deny];
        $range = $this->dutyPeriodRange($_POST['period'] ?? '');
        if (!$range) return ['result' => false, 'message' => 'Invalid cutoff period.'];
        $dept = (int) ($_POST['department_id'] ?? 0);

        // Checked before the upload is even opened: an import rewrites a whole
        // cutoff, so a view-only head must be turned away at the door.
        $this->loadScopeHelpers();
        if (function_exists('area_scope_ids') && area_scope_ids() !== [] && roster_editable_area_ids() === []) {
            return ['result' => false, 'message' => 'You have view-only access to this roster.'];
        }

        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return ['result' => false, 'message' => 'No file was received. Pick an .xlsx file and try again.'];
        }
        if ($f['size'] > 8 * 1024 * 1024) {
            return ['result' => false, 'message' => 'That file is over 8 MB — too big for a roster sheet.'];
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return ['result' => false, 'message' => 'Only .xlsx or .xls files can be imported.'];
        }

        require_once __DIR__ . '/vendor/autoload.php';
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($f['tmp_name']);
            $reader->setReadDataOnly(true);
            $book = $reader->load($f['tmp_name']);
        } catch (\Throwable $e) {
            return ['result' => false, 'message' => 'That file could not be opened as a spreadsheet.'];
        }
        /* ── Is this sheet even for the cutoff that is open? ──────────────── */
        //
        // Day columns match by DAY NUMBER, and every first-half cutoff in the
        // year is numbered 1…15 — so last cutoff's file lines up perfectly with
        // this one and would import into the wrong month silently. The caption
        // on the Roster tab is only a caption; this reads the stamp instead.
        $stampPeriod = '';
        $wrongDept   = 0;
        $stampSheet  = $book->getSheetByName('Shifts');
        if ($stampSheet) {
            $stampPeriod = trim((string) $stampSheet->getCell('G2')->getValue());
            $stampDept   = trim((string) $stampSheet->getCell('G3')->getValue());

            if ($stampPeriod !== '' && $stampPeriod !== (string) ($_POST['period'] ?? '')) {
                return ['result' => false, 'message' =>
                    'This sheet was exported for ' . $this->dutyPeriodLabel($stampPeriod)
                    . ', but you have ' . $this->dutyPeriodLabel($_POST['period'] ?? '') . ' open. '
                    . 'Switch the cutoff at the top of the page, or export a fresh sheet for this one.'];
            }
            // A department mismatch is a warning, not a refusal: every row is
            // matched by employee number and anyone outside the current view is
            // reported as unmatched anyway. Refusing would also block the
            // legitimate case of pasting two wards into one sheet.
            if ($stampDept !== '' && (int) $stampDept !== $dept && (int) $stampDept > 0 && $dept > 0) {
                $wrongDept = (int) $stampDept;
            }
        }

        $sheet = $book->getSheetByName('Roster') ?: $book->getSheet(0);
        // Formulas are NOT evaluated: the only formulas in this workbook are the
        // coverage counters at the bottom, which no employee row references, and
        // running PhpSpreadsheet's calculation engine over them would cost time
        // for a block this parser throws away anyway.
        $rows = $sheet->toArray(null, false, true, false);
        // A heading row and one employee is a legitimate sheet — a ward sending
        // a correction for a single nurse should not be told it is empty. The
        // real "this is not a roster" answer comes from the header search below,
        // which can say what is actually missing.
        if (count($rows) < 2) return ['result' => false, 'message' => 'That sheet is empty.'];

        /* ── Locate the header ────────────────────────────────────────────── */
        $hdr = $noCol = $nameCol = -1;
        foreach ($rows as $i => $r) {
            foreach ($r as $c => $v) {
                $t = strtolower(trim((string) $v));
                if ($t === 'employee no' || $t === 'employee no.' || $t === 'employee number') {
                    $hdr = $i; $noCol = $c; break 2;
                }
            }
        }
        // Name column second, over the whole header row — looked for separately
        // because it may sit either side of the number column once someone has
        // rearranged the sheet.
        if ($hdr >= 0) {
            foreach ($rows[$hdr] as $c => $v) {
                $t = strtolower(trim((string) $v));
                if ($t === 'employee' || $t === 'name' || $t === 'employee name') { $nameCol = $c; break; }
            }
        }
        if ($hdr < 0) {
            return ['result' => false, 'message' => 'This does not look like a duty-roster sheet — no "Employee No" heading was found. Export a fresh copy and fill that in.'];
        }

        /* ── Day columns, from the day numbers on the header row ──────────── */
        $days = [];
        for ($t = strtotime($range['from']); $t <= strtotime($range['to']); $t = strtotime('+1 day', $t)) {
            $days[(int) date('j', $t)] = date('Y-m-d', $t);
        }
        $colDate = [];
        foreach ($rows[$hdr] as $c => $v) {
            if ($c === $noCol || $c === $nameCol) continue;
            $n = trim((string) $v);
            if ($n === '' || !ctype_digit($n)) continue;
            if (isset($days[(int) $n])) $colDate[$c] = $days[(int) $n];
        }
        if (!$colDate) {
            return ['result' => false, 'message' => 'No day columns in this sheet match ' . date('M j', strtotime($range['from'])) . '–' . date('M j, Y', strtotime($range['to'])) . '. This file is probably for a different cutoff.'];
        }

        // Second guard: the weekday row. The day NUMBERS repeat every month but
        // the weekdays under them do not — Sep 1 is a Tuesday and Nov 1 a Sunday
        // — so a row of weekday names that disagrees with the open cutoff means
        // the file belongs to a different month.
        //
        // Checked even when the stamp already agreed, because a hand-edited
        // stamp is exactly the case worth catching: someone who retyped the
        // cutoff cell to make an old sheet go through leaves this row behind.
        if (isset($rows[$hdr + 1])) {
            $wd = $rows[$hdr + 1];
            $DOW = ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 1, 'sun' => 1];
            $checked = $wrong = 0;
            foreach ($colDate as $c => $date) {
                $t = strtolower(substr(trim((string) ($wd[$c] ?? '')), 0, 3));
                // Only judge cells that are actually weekday names. On a sheet
                // typed by hand there is no weekday row at all, and the row
                // under the heading is the first nurse — reading "OFF" as a
                // failed weekday rejected a perfectly good file.
                if (!isset($DOW[$t])) continue;
                $checked++;
                if ($t !== strtolower(date('D', strtotime($date)))) $wrong++;
            }
            if ($checked >= 3 && $wrong > 0) {
                return ['result' => false, 'message' =>
                    'The weekdays in this sheet do not line up with '
                    . $this->dutyPeriodLabel($_POST['period'] ?? '')
                    . ' — day 1 is a ' . date('l', strtotime($range['from']))
                    . ' in this cutoff. The file looks like it is for a different month.'];
            }
        }

        /* ── Who we are allowed to touch ──────────────────────────────────── */
        $employees = $this->dutyRosterEmployees($dept, $range['from'], $range['to']);
        $byNo = $byName = [];
        foreach ($employees as $e) {
            if ($e['employee_no'] !== '') $byNo[strtolower(trim($e['employee_no']))] = $e;
            $byName[$this->dutyNameKey($e['name'])] = $e;
        }
        $zones = $this->dutyZoneMap($range['from'], $range['to'], array_column($employees, 'id'));

        // Codes, matched case-insensitively — nobody types NOC in the same case twice.
        $codeToId = [];
        foreach ($this->dutyShiftCodes() as $s) $codeToId[strtolower($s['code'])] = $s['id'];

        /* ── What is planned now, to diff against ─────────────────────────── */
        $current = [];
        if ($employees) {
            $ids = implode(',', array_map('intval', array_column($employees, 'id')));
            $cq = $this->db->query("SELECT employee_id, work_date, schedule_id, is_rest_day
                                    FROM employee_day_schedule
                                    WHERE employee_id IN ($ids)
                                      AND work_date BETWEEN '" . $this->db->real_escape_string($range['from']) . "'
                                                        AND '" . $this->db->real_escape_string($range['to']) . "'");
            while ($cq && ($c = $cq->fetch_assoc())) {
                $current[$c['employee_id'] . '|' . $c['work_date']] = [
                    's' => $c['schedule_id'] !== null ? (int) $c['schedule_id'] : null,
                    'r' => (int) $c['is_rest_day'],
                ];
            }
        }

        /* ── Walk the rows ────────────────────────────────────────────────── */
        $changes = [];
        $problems = [];
        $seen = ['rows' => 0, 'blank_rows' => 0, 'unknown' => 0, 'locked' => 0, 'same' => 0, 'recovered' => 0, 'cleared' => 0];
        $unmatched = [];

        for ($i = $hdr + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $no = trim((string) ($r[$noCol] ?? ''));
            $nm = $nameCol >= 0 ? trim((string) ($r[$nameCol] ?? '')) : '';
            if ($no === '' && $nm === '') continue;

            $emp = $byNo[strtolower($no)] ?? ($nm !== '' ? ($byName[$this->dutyNameKey($nm)] ?? null) : null);
            if (!$emp) {
                // The coverage block at the bottom has no employee number, so it
                // falls out here by itself rather than needing a row count.
                if ($no !== '') $unmatched[] = $no . ($nm !== '' ? ' (' . $nm . ')' : '');
                continue;
            }

            // Two passes over the row: the first decides whether the row was
            // plotted at all. A blank cell means "clear this day" only in a row
            // that has entries — otherwise exporting 150 people and filling in
            // two of them would wipe the other 148.
            $vals = [];
            $touched = false;
            foreach ($colDate as $c => $date) {
                $raw = $r[$c] ?? null;
                $vals[$c] = $raw;
                if (trim((string) $raw) !== '') $touched = true;
            }
            $seen['rows']++;
            if (!$touched) { $seen['blank_rows']++; continue; }

            foreach ($vals as $c => $raw) {
                $date = $colDate[$c];
                $txt  = trim((string) $raw);
                $k    = $emp['id'] . '|' . $date;

                if (($zones[$k] ?? '') === 'locked') { if ($txt !== '') $seen['locked']++; continue; }

                if ($txt === '' || $txt === '-' || $txt === '—') {
                    $next = ['s' => null, 'r' => 0];
                    if (!isset($current[$k])) continue;      // already nothing there
                    $seen['cleared']++;
                } else {
                    $lc = strtolower($txt);
                    if ($lc === 'off' || $lc === 'rest' || $lc === 'rd' || $lc === 'restday') {
                        $next = ['s' => null, 'r' => 1];
                    } elseif (isset($codeToId[$lc])) {
                        $next = ['s' => $codeToId[$lc], 'r' => 0];
                    } elseif (preg_match('/^(.*?)\+(off|rest|rd|restday)$/i', $lc, $mm) && isset($codeToId[trim($mm[1])])) {
                        // Combo: "CODE+OFF" (see export-duty-roster.php) — a shift
                        // stays on file for the day's hours, but the day is ALSO a
                        // rest day. Mirrors the web grid's "Rest day too" toggle.
                        $next = ['s' => $codeToId[trim($mm[1])], 'r' => 1];
                    } elseif (($rec = $this->dutyRecoverCode($raw, $codeToId)) !== null) {
                        // Excel turned the code into a date on the way in — see
                        // the note in export-duty-roster.php. "6-2" comes back as
                        // 02/06, and reading the month and day back out gives the
                        // code again. Recovered, not guessed, and reported.
                        $next = ['s' => $rec, 'r' => 0];
                        $seen['recovered']++;
                    } else {
                        $seen['unknown']++;
                        if (count($problems) < 12) {
                            $problems[] = $emp['name'] . ' · ' . date('M j', strtotime($date)) . ' · "' . mb_substr($txt, 0, 12) . '" is not a shift code';
                        }
                        continue;
                    }
                }

                $cur = $current[$k] ?? ['s' => null, 'r' => 0];
                if ($cur['s'] === $next['s'] && $cur['r'] === $next['r']) { $seen['same']++; continue; }
                $changes[] = ['e' => $emp['id'], 'd' => $date, 's' => $next['s'], 'r' => $next['r']];
            }
        }

        if (count($changes) > 5000) {
            return ['result' => false, 'message' => 'That sheet would change ' . count($changes) . ' days at once, which is past the safety limit. Import one department at a time.'];
        }
        if ($unmatched) {
            $show = array_slice(array_unique($unmatched), 0, 6);
            $problems[] = count(array_unique($unmatched)) . ' row(s) matched nobody in this view: ' . implode(', ', $show);
        }
        if ($wrongDept) {
            $dq = $this->db->query("SELECT name FROM department WHERE id = " . (int) $wrongDept . " LIMIT 1");
            $dn = ($dq && ($d = $dq->fetch_assoc())) ? $d['name'] : ('department #' . $wrongDept);
            array_unshift($problems, 'This sheet was exported for ' . $dn . ', not the department you have open.');
        }

        return [
            'result'    => true,
            'changes'   => $changes,
            'rows'      => $seen['rows'],
            'blank_rows' => $seen['blank_rows'],
            'unchanged' => $seen['same'],
            'cleared'   => $seen['cleared'],
            'locked'    => $seen['locked'],
            'unknown'   => $seen['unknown'],
            'recovered' => $seen['recovered'],
            'problems'  => $problems,
            'period'    => $_POST['period'] ?? '',
        ];
    }

    /**
     * Flip the whole-page freeze. Deliberately a hard role check rather than
     * page_allowed()/can_edit() — those answer "can this role plan a ward",
     * which several roles can; this answers "is this person the administrator
     * account", which only role 1 is, so it stays out of ACTION_PAGE_MAP.
     */
    /**
     * The duty roster's change history, for the drawer.
     *
     * Two shapes, one endpoint:
     *   employee_id + work_date  → that one cell's story, oldest change first
     *   period only              → everything that happened to the cutoff
     *
     * A cell's history includes the period-wide acts that swept over it (publish,
     * discard, copy, recompute) — those are what actually changed the day for a
     * lot of the questions people ask, and hiding them would make a published
     * cell look untouched since the last paint.
     */
    function duty_roster_history()
    {
        $period = (string) ($_POST['period'] ?? '');
        $eid    = (int) ($_POST['employee_id'] ?? 0);
        $date   = (string) ($_POST['work_date'] ?? '');
        $cell   = $eid > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);

        // Read access follows the grid: if you may see the roster you may see how
        // it got that way. A scoped session can only ask about its own people
        // because dutyRosterEmployees() is what fed it the ids in the first place;
        // re-check anyway so a hand-made request cannot read another ward.
        if ($cell) {
            $this->loadScopeHelpers();
            if (function_exists('area_scope_ids') && area_scope_ids() !== []) {
                $ok = implode(',', array_map('intval', area_scope_ids()));
                $r  = $this->db->query("SELECT COUNT(*) c FROM employee WHERE id = $eid AND area_id IN ($ok)");
                if (!$r || (int) ($r->fetch_assoc()['c'] ?? 0) === 0) {
                    return ['result' => false, 'message' => 'That employee is outside your ward.'];
                }
            }
        }

        $where = [];
        $pE = $this->db->real_escape_string($period);
        if ($cell) {
            $dE = $this->db->real_escape_string($date);
            // The cell's own entries, plus the sweeps over its cutoff.
            $where[] = "((l.employee_id = $eid AND l.work_date = '$dE')"
                     . ($period !== '' ? " OR (l.period = '$pE' AND l.employee_id IS NULL)" : '')
                     . ")";
        } elseif ($period !== '') {
            $where[] = "l.period = '$pE'";
        } else {
            return ['result' => false, 'message' => 'Nothing to look up.'];
        }

        $sql = "SELECT l.id, l.employee_id, l.work_date, l.action, l.detail, l.note,
                       l.old_schedule_id, l.old_is_rest_day, l.new_schedule_id, l.new_is_rest_day,
                       l.was_published, l.created_at,
                       u.name AS user_name,
                       CONCAT(e.lastname, ', ', e.firstname) AS employee_name
                FROM duty_roster_log l
                LEFT JOIN users u    ON u.id = l.user_id
                LEFT JOIN employee e ON e.id = l.employee_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT " . ($cell ? 60 : 200);

        $q = $this->db->query($sql);
        if (!$q) {
            // The migration has not been run on this install yet — say so plainly
            // instead of surfacing a SQL error in the drawer.
            return ['result' => true, 'rows' => [], 'scope' => $cell ? 'cell' : 'period',
                    'message' => 'History is not set up on this database yet.'];
        }

        $rows = [];
        while ($r = $q->fetch_assoc()) {
            $rows[] = [
                'id'          => (int) $r['id'],
                'action'      => (string) $r['action'],
                'detail'      => (string) $r['detail'],
                'note'        => $r['note'],
                'work_date'   => $r['work_date'],
                'employee'    => $r['employee_name'],
                'was_published' => (int) $r['was_published'],
                'by'          => $r['user_name'] ?: null,
                'created_at'  => (string) $r['created_at'],
                'scope'       => $r['employee_id'] === null ? 'period' : 'cell',
            ];
        }

        return ['result' => true, 'rows' => $rows, 'scope' => $cell ? 'cell' : 'period'];
    }

    function duty_roster_set_lock()
    {
        if ((int) ($_SESSION['login_role'] ?? 0) !== 1) {
            return ['result' => false, 'message' => 'Only an administrator may lock or unlock the duty roster.'];
        }
        $lock = ((int) ($_POST['lock'] ?? 0)) ? 1.0 : 0.0;
        $uid  = $_SESSION['login_id'] ?? null;
        $key  = 'duty_roster_locked';
        $stmt = $this->db->prepare(
            "INSERT INTO pay_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value=?, updated_by=?"
        );
        $stmt->bind_param('sdidi', $key, $lock, $uid, $lock, $uid);
        $stmt->execute();

        // Not tied to a cutoff — the lock is board-wide, so it carries no period.
        $this->dutyLog('', $lock ? 'lock' : 'unlock',
            $lock ? 'Roster locked for everyone' : 'Roster unlocked — editing reopened');

        return [
            'result'  => true,
            'locked'  => (bool) $lock,
            'message' => $lock
                ? 'Duty roster locked. No one — including you — can make changes until you unlock it.'
                : 'Duty roster unlocked. Editing is open again.',
        ];
    }

    // "2026-09-1" → "Sep 1 – Sep 15, 2026", for messages that have to name two
    // cutoffs and make the difference obvious at a glance.
    private function dutyPeriodLabel($period): string
    {
        $r = $this->dutyPeriodRange($period);
        if (!$r) return 'an unknown cutoff';
        return date('M j', strtotime($r['from'])) . ' – ' . date('M j, Y', strtotime($r['to']));
    }

    // "ABAD, QUERWIN" / "abad querwin" / "Abad,Querwin" all collapse to the same
    // key, so a sheet retyped by hand still matches when the employee number is
    // missing. Number is always tried first.
    private function dutyNameKey(string $s): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($s));
    }

    /**
     * A shift code Excel swallowed as a date, turned back into a code.
     *
     * Only reached when the literal text did not match. "6-2" stored as 2 June
     * gives month 6, day 2 → "6-2"; a day-first locale gives "2-6", so both
     * orders are tried and only an exact hit on a real code is accepted.
     * Returns the schedule id, or null to leave it as an error.
     */
    private function dutyRecoverCode($raw, array $codeToId)
    {
        if ($raw instanceof \DateTimeInterface) {
            $m = (int) $raw->format('n'); $d = (int) $raw->format('j');
        } elseif (is_numeric($raw) && $raw > 1 && $raw < 90000) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
            } catch (\Throwable $e) { return null; }
            $m = (int) $dt->format('n'); $d = (int) $dt->format('j');
        } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', trim((string) $raw), $mm)) {
            // ISO text, which is what LibreOffice and Numbers write out when
            // they have already decided the cell was a date.
            $m = (int) $mm[2]; $d = (int) $mm[3];
        } elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})(?:[/-]\d{2,4})?$#', trim((string) $raw), $mm)) {
            $m = (int) $mm[1]; $d = (int) $mm[2];
        } else {
            return null;
        }
        foreach ([$m . '-' . $d, $d . '-' . $m] as $guess) {
            if (isset($codeToId[$guess])) return $codeToId[$guess];
        }
        return null;
    }

    function get_employee_schedule_history()
    {
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $stmt = $this->db->prepare(
            "SELECT es.*, ws.description, ws.start_time, ws.end_time, ws.total_hours,
                    ws.is_graveyard, ws.has_nsd, ws.nsd_rate,
                    CONCAT(u.firstname,' ',u.lastname) AS changed_by_name
             FROM employee_schedules es
             INNER JOIN work_schedules ws ON ws.id = es.schedule_id
             LEFT JOIN users u ON u.id = es.changed_by
             WHERE es.employee_id = ?
             ORDER BY es.effective_from DESC"
        );
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ['result' => true, 'data' => $rows];
    }

    /**
     * Compact employee summary for the quick-view drawer
     * (component/employee_quick_view.php — daily board, payroll sheet, DTR
     * screens). Replaces the old employee-view-server.php and keeps its
     * visibility rules: government IDs and the bank account ship MASKED
     * (last 4) for everyone, and compensation is omitted for Timekeepers
     * (role 5) and the view-only DTR role (6). Mapped read-only onto
     * 'employee-details' in db_connect.php, so every role that may LOOK at
     * an employee record can call it — and nobody else.
     */
    function employee_quick_view()
    {
        $id = intval($_REQUEST['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing employee id'];

        $stmt = $this->db->prepare(
            "SELECT e.id, e.employee_no, e.employee_code, e.firstname, e.middlename,
                    e.lastname, e.ext, e.bday, e.rate_type, e.status,
                    e.salary, e.basic_pay, e.allowance_rate,
                    e.sss_no, e.ph_no, e.hdmf_no, e.tin_no, e.bank_account_no,
                    p.name AS position, d.name AS department,
                    c.clasification AS classification, b.bank_name
             FROM employee e
             LEFT JOIN position p ON p.id = e.position_id
             LEFT JOIN department d ON d.id = e.department_id
             LEFT JOIN clasification c ON c.id = e.clasification_id
             LEFT JOIN banks b ON b.id = e.bank_id
             WHERE e.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        if (!$emp) return ['result' => false, 'message' => 'Employee not found'];

        // The shift in effect today, same date-window rule the daily board uses.
        $s = $this->db->prepare(
            "SELECT ws.description, ws.start_time, ws.end_time, es.rest_days
             FROM employee_schedules es
             INNER JOIN work_schedules ws ON ws.id = es.schedule_id
             WHERE es.employee_id = ? AND es.effective_from <= CURDATE()
               AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
             ORDER BY es.effective_from DESC LIMIT 1"
        );
        $s->bind_param('i', $id);
        $s->execute();
        $shift = $s->get_result()->fetch_assoc();
        if ($shift) {
            $shift['time'] = date('h:i A', strtotime($shift['start_time']))
                . ' – ' . date('h:i A', strtotime($shift['end_time']));
            $DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $shift['rest'] = implode(', ', array_map(
                fn($d) => $DAYS[(int) $d] ?? $d,
                array_filter(array_map('trim', explode(',', (string) $shift['rest_days'])), fn($v) => $v !== '')
            ));
            unset($shift['rest_days']);
        }

        foreach (['sss_no', 'ph_no', 'hdmf_no', 'tin_no', 'bank_account_no'] as $k) {
            $v = trim((string) $emp[$k]);
            $emp[$k] = $v === '' ? null : $v;
        }

        // Compensation is a glance for roles that may see pay elsewhere; the
        // same roles employee-view-server.php used to blank it for.
        $role = (int) ($_SESSION['login_role'] ?? 0);
        $pay = null;
        if (!(function_exists('is_timekeeper') && is_timekeeper($role)) && $role !== 6) {
            $pay = [
                'rate_type' => $emp['rate_type'],
                'rate'      => (float) ($emp['rate_type'] === 'monthly' ? $emp['salary'] : $emp['basic_pay']),
                'allowance' => (float) $emp['allowance_rate'],
            ];
        }
        unset($emp['salary'], $emp['basic_pay'], $emp['allowance_rate']);

        return ['result' => true, 'employee' => $emp, 'shift' => $shift, 'pay' => $pay];
    }

    /**
     * Full unmasked employee record for the "Quick Edit" modal on the
     * employee list (employee.php) — populates component/add_employee_form.php
     * client-side, the same fields employee-details.php pre-fills server-side
     * when it renders that form for its own "Edit Details" button.
     *
     * Gated as a write endpoint (ACTION_PAGE_MAP → 'employee', not in
     * READ_ONLY_ACTIONS): only a role that may actually save changes gets the
     * unmasked record back.
     */
    function get_employee_edit()
    {
        $id = intval($_REQUEST['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing employee id'];

        $stmt = $this->db->prepare("SELECT * FROM employee WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        if (!$emp) return ['result' => false, 'message' => 'Employee not found'];

        // Approvers may only edit employees inside their own scope — the same
        // boundary employee-details.php enforces when opening the page.
        // Area is checked first and on its own: an area-scoped account usually
        // has no department_id at all, so a department-only test would read as
        // "unscoped" and wave through every ward in the hospital.
        require_once __DIR__ . '/dept-scope.php';
        $areas = area_scope_ids();
        if ($areas !== []) {
            if (!in_array((int) $emp['area_id'], $areas, true)) {
                return ['result' => false, 'message' => "You don't have access to this employee's record."];
            }
        } elseif (dept_scope_id() > 0 && (int) $emp['department_id'] !== dept_scope_id()) {
            return ['result' => false, 'message' => "You don't have access to this employee's record."];
        }

        // Portal login email — ADMINISTRATOR ONLY, mirrors save_employee() and
        // the form itself, which only render/accept it for role 1.
        $emp['portal_username'] = '';
        if ((int) ($_SESSION['login_role'] ?? 0) === 1) {
            $pt = $this->db->query("SHOW TABLES LIKE 'employee_portal_accounts'");
            if ($pt && $pt->num_rows) {
                $pq = $this->db->prepare("SELECT username FROM employee_portal_accounts WHERE employee_id = ? LIMIT 1");
                $pq->bind_param('i', $id);
                $pq->execute();
                if ($pr = $pq->get_result()->fetch_assoc()) $emp['portal_username'] = $pr['username'];
            }
        }

        return ['result' => true, 'employee' => $emp];
    }

    function save_attendance_request()
    {
        $employee_id  = intval($_POST['employee_id'] ?? 0);
        $request_type = trim($_POST['request_type'] ?? '');
        $request_date = trim($_POST['request_date'] ?? '');
        $reason       = trim($_POST['reason'] ?? '');
        $time_in      = trim($_POST['claimed_time_in'] ?? '') ?: null;
        $time_out     = trim($_POST['claimed_time_out'] ?? '') ?: null;
        $ot_hours     = isset($_POST['ot_hours_requested']) && $_POST['ot_hours_requested'] !== ''
            ? floatval($_POST['ot_hours_requested']) : null;
        $notes        = trim($_POST['notes'] ?? '');

        $valid_types = array_merge(['incident'], ATT_REQUEST_HOUR_TYPES);
        if (!$employee_id || !in_array($request_type, $valid_types, true) || !$request_date || !$reason) {
            return ['result' => false, 'message' => 'Missing required fields'];
        }

        // Same ceiling the employee portal enforces: hours can only be filed
        // against scans that actually show them — past the shift end on a
        // regular day, the whole credited duty on a rest day (ot_request_limit).
        if (in_array($request_type, ATT_REQUEST_HOUR_TYPES, true)) {
            $what = $request_type === 'rest_day' ? 'rest-day' : 'overtime';
            if (!$ot_hours) {
                return ['result' => false, 'message' => 'Please provide the number of ' . $what . ' hours requested.'];
            }
            $lim = ot_request_limit($this->db, $employee_id, $request_date);
            if (!$lim['allowed']) {
                return ['result' => false, 'message' => $lim['message'], 'ot_limit' => $lim];
            }
            // The date itself decides which filing it needs — a rest day cannot
            // be filed as plain overtime, or approval would write the duty onto
            // the row and pay it twice.
            if ($lim['request_type'] !== $request_type) {
                return [
                    'result'  => false,
                    'message' => $lim['request_type'] === 'rest_day'
                        ? 'That date is a rest day — file it as rest-day work, not overtime.'
                        : 'That date is a regular working day — file it as overtime, not rest-day work.',
                    'ot_limit' => $lim,
                ];
            }
            if ($ot_hours < OT_REQUEST_MIN_HOURS) {
                return ['result' => false, 'message' => 'The smallest ' . $what . ' that can be filed is ' . OT_REQUEST_MIN_HOURS . ' hr.', 'ot_limit' => $lim];
            }
            if ($ot_hours > $lim['max_hours'] + 0.001) {
                return [
                    'result'   => false,
                    'message'  => 'Only up to ' . $lim['max_hours'] . ' hr of ' . $what . ' can be filed for that date. ' . $lim['message'],
                    'ot_limit' => $lim,
                ];
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO attendance_requests
             (employee_id, request_type, request_date, reason, claimed_time_in, claimed_time_out, ot_hours_requested, notes)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('isssssds', $employee_id, $request_type, $request_date, $reason, $time_in, $time_out, $ot_hours, $notes);
        if (!$stmt->execute()) {
            return ['result' => false, 'message' => $stmt->error];
        }

        $erow  = $this->db->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $employee_id")->fetch_assoc();
        $ename = $erow['n'] ?? 'Employee';
        $label = att_request_label($request_type);
        foreach ([1, 8, 9] as $role) {
            $this->notifyRole(
                $role,
                'New ' . $label,
                "$ename filed a $label for " . date('M d, Y', strtotime($request_date)) . '.',
                'ri-error-warning-line',
                'warning',
                'index.php?page=attendance-requests'
            );
        }

        return ['result' => true, 'message' => 'Request submitted. Awaiting approval.'];
    }

    /**
     * One attendance request, for the shared review modal.
     *
     * The modal is opened from two screens that hold different amounts of the
     * row already (the requests queue has all of it, a DTR record card has only
     * the id), so it always reads the request from here instead of from
     * whatever the caller happened to have.
     */
    function get_attendance_request()
    {
        $id   = intval($_POST['id'] ?? 0);
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!in_array($role, [1, 8, 9], true)) return ['result' => false, 'message' => 'Not authorized'];
        if (!$id) return ['result' => false, 'message' => 'Invalid request'];

        $q = $this->db->query(
            "SELECT ar.*, CONCAT(e.lastname, ', ', e.firstname) AS employee_name, e.employee_no
             FROM attendance_requests ar
             INNER JOIN employee e ON e.id = ar.employee_id
             WHERE ar.id = $id LIMIT 1"
        );
        $r = $q ? $q->fetch_assoc() : null;
        if (!$r) return ['result' => false, 'message' => 'Request not found'];

        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = " . (int) $r['employee_id'] . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This request belongs to another department.'];
        }

        return ['result' => true, 'request' => [
            'id'          => (int) $r['id'],
            'employee_id' => (int) $r['employee_id'],
            'employee'    => $r['employee_name'],
            'employee_no' => $r['employee_no'],
            'type'        => $r['request_type'],
            'date'        => $r['request_date'],
            'reason'      => $r['reason'],
            'time_in'     => $r['claimed_time_in'],
            'time_out'    => $r['claimed_time_out'],
            'ot_hours'    => $r['ot_hours_requested'] !== null ? (float) $r['ot_hours_requested'] : null,
            'notes'       => (string) $r['notes'],
            'attachment'  => (string) $r['attachment'],
            'status'      => (int) $r['status'],
            'filed_at'    => date('M d, Y g:i A', strtotime($r['created_at'])),
        ]];
    }

    /** The filing ceiling for one employee+date, for the approver's edit form. */
    function attendance_request_limit()
    {
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!in_array($role, [1, 8, 9], true)) {
            return ['result' => false, 'message' => 'Not authorized'];
        }
        return [
            'result' => true,
            'limit'  => ot_request_limit(
                $this->db,
                (int) ($_POST['employee_id'] ?? 0),
                trim($_POST['request_date'] ?? ''),
                (int) ($_POST['exclude_id'] ?? 0)
            ),
        ];
    }

    /**
     * Correct a PENDING attendance request before deciding it.
     *
     * The approver is the one who knows the day: an employee files 7.5 hrs when
     * the slip says 6, or claims 8:00 AM when the roster started at 7:00. Before
     * this, the only ways to fix that were to reject the request and make them
     * re-file, or to approve the wrong figure and repair the DTR afterwards.
     *
     * Decided requests are immutable — they have already written to the DTR (an
     * incident repair, an OT figure), and a silent edit afterwards would leave
     * the record and the request telling different stories.
     *
     * Hours are re-checked against the day's scans exactly as filing does, with
     * this request excluded from the "already filed" total — otherwise the row
     * being edited would count against its own ceiling.
     */
    function update_attendance_request()
    {
        $id   = intval($_POST['id'] ?? 0);
        $role = (int) ($_SESSION['login_role'] ?? 0);

        if (!in_array($role, [1, 8, 9], true) || !can_edit('attendance-requests', $role)) {
            return ['result' => false, 'message' => 'Your role cannot edit this request.'];
        }
        if (!$id) return ['result' => false, 'message' => 'Invalid request'];

        $req = $this->db->query("SELECT * FROM attendance_requests WHERE id = $id")->fetch_assoc();
        if (!$req) return ['result' => false, 'message' => 'Request not found'];
        if ((int) $req['status'] !== 0) {
            return ['result' => false, 'message' => 'This request was already decided — it can no longer be edited.'];
        }

        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = " . (int) $req['employee_id'] . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This request belongs to another department.'];
        }

        $employee_id = (int) $req['employee_id'];
        $type        = (string) $req['request_type'];
        $reason      = trim($_POST['reason'] ?? '') ?: $req['reason'];
        $notes       = isset($_POST['notes']) ? trim($_POST['notes']) : (string) $req['notes'];
        $time_in     = $req['claimed_time_in'];
        $time_out    = $req['claimed_time_out'];
        $ot_hours    = $req['ot_hours_requested'] !== null ? (float) $req['ot_hours_requested'] : null;

        if ($type === 'incident') {
            $time_in  = trim($_POST['claimed_time_in'] ?? '')  ?: $time_in;
            $time_out = trim($_POST['claimed_time_out'] ?? '') ?: $time_out;
            if (!$time_in || !$time_out) {
                return ['result' => false, 'message' => 'An incident report needs both a claimed time in and time out.'];
            }
        }

        if (in_array($type, ATT_REQUEST_HOUR_TYPES, true)) {
            if (trim($_POST['ot_hours_requested'] ?? '') === '') {
                return ['result' => false, 'message' => 'Please provide the hours.'];
            }
            $ot_hours = (float) $_POST['ot_hours_requested'];
            $what     = $type === 'rest_day' ? 'rest-day' : 'overtime';
            // Excluding THIS request, so editing 7.5 → 6 is measured against the
            // day's scans and not against the 7.5 already on the row.
            $lim = ot_request_limit($this->db, $employee_id, $req['request_date'], $id);
            if (!$lim['allowed']) {
                return ['result' => false, 'message' => $lim['message'], 'ot_limit' => $lim];
            }
            if ($ot_hours < OT_REQUEST_MIN_HOURS) {
                return ['result' => false, 'message' => 'The smallest ' . $what . ' that can be filed is ' . OT_REQUEST_MIN_HOURS . ' hr.', 'ot_limit' => $lim];
            }
            if ($ot_hours > $lim['max_hours'] + 0.001) {
                return [
                    'result'   => false,
                    'message'  => 'Only up to ' . $lim['max_hours'] . ' hr of ' . $what . ' is supported by the scans for that date. ' . $lim['message'],
                    'ot_limit' => $lim,
                ];
            }
        }

        $stmt = $this->db->prepare(
            "UPDATE attendance_requests
                SET reason = ?, claimed_time_in = ?, claimed_time_out = ?, ot_hours_requested = ?, notes = ?
              WHERE id = ? AND status = 0"
        );
        $stmt->bind_param('sssdsi', $reason, $time_in, $time_out, $ot_hours, $notes, $id);
        if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];

        // The employee sees what the approver changed BEFORE the decision lands,
        // so an approved 6 hrs against a filed 7.5 is never a silent haircut.
        $changed = [];
        if ((float) ($req['ot_hours_requested'] ?? 0) !== (float) ($ot_hours ?? 0)) {
            $changed[] = 'hours ' . rtrim(rtrim(number_format((float) $req['ot_hours_requested'], 2), '0'), '.')
                       . ' → ' . rtrim(rtrim(number_format((float) $ot_hours, 2), '0'), '.');
        }
        if ($req['claimed_time_in'] !== $time_in || $req['claimed_time_out'] !== $time_out) {
            $changed[] = 'claimed time updated';
        }
        if ($changed) {
            $this->notifyEmployee(
                $employee_id,
                'Your request was adjusted',
                att_request_label($type, true) . ' for ' . date('M d, Y', strtotime($req['request_date']))
                    . ' — ' . implode(', ', $changed) . '. It is still awaiting a decision.',
                'ri-edit-line',
                'warning',
                'employee-portal.php?tab=att-requests'
            );
        }

        return [
            'result'  => true,
            'message' => 'Request updated',
            'request' => [
                'id'        => $id,
                'reason'    => $reason,
                'time_in'   => $time_in,
                'time_out'  => $time_out,
                'ot_hours'  => $ot_hours,
                'notes'     => $notes,
            ],
        ];
    }

    function decide_attendance_request()
    {
        $id      = intval($_POST['id'] ?? 0);
        $status  = intval($_POST['status'] ?? 0); // 1 approve, 2 reject
        $remarks = trim($_POST['remarks'] ?? '');
        $uid     = $_SESSION['login_id'] ?? null;
        $role    = (int) ($_SESSION['login_role'] ?? 0);

        // Approver role AND write access: can_edit() keeps read-only roles out
        // (HR can open this screen but not act on it — HR_READONLY_PAGES).
        if (!in_array($role, [1, 8, 9], true) || !can_edit('attendance-requests', $role)) {
            return ['result' => false, 'message' => 'Your role cannot decide this request.'];
        }
        if (!$id || !in_array($status, [1, 2], true)) {
            return ['result' => false, 'message' => 'Invalid request'];
        }

        $req = $this->db->query("SELECT * FROM attendance_requests WHERE id = $id")->fetch_assoc();
        if (!$req) return ['result' => false, 'message' => 'Request not found'];
        if ($req['status'] != 0) return ['result' => false, 'message' => 'Request already decided'];

        // Scoped Department Heads may only decide their own department's requests.
        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = " . (int)$req['employee_id'] . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This request belongs to another department.'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE attendance_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), reviewer_remarks=? WHERE id=?"
            );
            $stmt->bind_param('iisi', $status, $uid, $remarks, $id);
            $stmt->execute();

            // Incident reports, once approved, write/repair the actual DTR_details row.
            if ($status == 1 && $req['request_type'] === 'incident' && $req['claimed_time_in'] && $req['claimed_time_out']) {
                $this->applyIncidentToDtr($req);
            }

            // Overtime requests, once approved, auto-fill the DTR_details.overtime figure
            // instead of leaving it at 0 pending a manual edit by the admin. A
            // rest-day request only authorizes the day — applyOvertimeToDtr
            // writes nothing for it (the scans already priced the row).
            $ot_applied = true;
            if ($status == 1 && in_array($req['request_type'], ATT_REQUEST_HOUR_TYPES, true) && $req['ot_hours_requested'] !== null) {
                $ot_applied = $this->applyOvertimeToDtr($req);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }

        // Notify the employee on their portal bell (recipient_type='employee').
        $label   = att_request_label($req['request_type']);
        $datestr = $req['request_date'] ? date('M d, Y', strtotime($req['request_date'])) : '';
        $emp_link = 'employee-portal.php?tab=att-requests';
        if ($status == 1) {
            $this->notifyEmployee(
                (int) $req['employee_id'],
                'Request approved',
                "Your $label" . ($datestr ? " for $datestr" : '') . ' was approved.' . ($remarks ? " Note: $remarks" : ''),
                'ri-checkbox-circle-line',
                'success',
                $emp_link
            );
        } else {
            $this->notifyEmployee(
                (int) $req['employee_id'],
                'Request rejected',
                "Your $label" . ($datestr ? " for $datestr" : '') . ' was rejected.' . ($remarks ? " Reason: $remarks" : ''),
                'ri-close-circle-line',
                'danger',
                $emp_link
            );
        }

        $msg = $status == 1 ? 'Request approved' : 'Request rejected';
        if (!$ot_applied) {
            $msg .= '. Warning: no DTR record exists for that date yet, so the OT hours were NOT written to the DTR — enter them on the DTR once attendance for that date is imported.';
        }
        return ['result' => true, 'message' => $msg];
    }

    /**
     * Approved incident report → write/repair the day's DTR_details row.
     *
     * Where the row lands, in order of preference:
     *   1. the row this employee already has for that date (repaired in place,
     *      whatever batch it is in — so the fix is visible in DTR Documents)
     *   2. a new row in the DTR batch whose period covers the date
     *      (the employee's own site first)
     *   3. a new one-day fallback batch — only when no batch covers the date,
     *      and never with site_id 0 (the FK made that fail silently before)
     *
     * Figures (late / undertime / overtime / NSD / holiday type) are computed
     * exactly like the manual edit path (edit_dtr_time) so an incident repair
     * and a manual repair always agree. The row is left PENDING so it still
     * passes through the normal DTR approval flow. Every write is checked and
     * throws on failure so decide_attendance_request rolls the approval back.
     */
    private function applyIncidentToDtr($req)
    {
        $employee_id = (int) $req['employee_id'];
        $date        = $this->db->real_escape_string($req['request_date']);

        $in_ts  = strtotime($date . ' ' . $req['claimed_time_in']);
        $out_ts = strtotime($date . ' ' . $req['claimed_time_out']);
        if ($out_ts <= $in_ts) $out_ts = strtotime('+1 day', $out_ts);

        // Figures via the shared day math (dtr_compute_day, db_connect.php).
        $c = dtr_compute_day($this->db, $employee_id, $date, [$in_ts, $out_ts]);
        $work_hours = $c['work_hours'];
        $overtime   = $c['overtime'];
        $undertime  = $c['undertime'];
        $late       = $c['late'];
        $nsd_hours  = $c['nsd_hours'];
        $day_type   = $c['day_type'];

        $logs = json_encode([
            ['dateTime' => date('Y-m-d H:i:s', $in_ts), 'type' => 'incident'],
            ['dateTime' => date('Y-m-d H:i:s', $out_ts), 'type' => 'incident'],
        ]);

        // ── 1. Repair the employee's existing row for that date, in place ──
        $existing = $this->db->query("
            SELECT id FROM DTR_details
            WHERE employee_id = $employee_id AND date_time = '$date'
            ORDER BY id DESC LIMIT 1
        ")->fetch_assoc();
        if ($existing) {
            $existing_id = (int) $existing['id'];
            $stmt = $this->db->prepare(
                "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, late=?, undertime=?,
                 day_type=?, nsd_hours=?, is_complete=1, attendance_type='incident',
                 schedule_id=?, day_hours=?, is_rest_day=?, sched_start=?, sched_end=?, sched_break=?, sched_graveyard=?,
                 status=0, decision_note=NULL, decided_by=NULL, decided_at=NULL WHERE id=?"
            );
            $stmt->bind_param('sddddsdidissiii', $logs, $work_hours, $overtime, $late, $undertime, $day_type, $nsd_hours,
                              $c['schedule_id'], $c['day_hours'], $c['is_rest_day'],
                              $c['sched_start'], $c['sched_end'], $c['sched_break'], $c['sched_graveyard'], $existing_id);
            if (!$stmt->execute()) throw new Exception('Could not update the DTR record: ' . $stmt->error);
            return;
        }

        // ── 2. No row yet — put one in the batch that covers the date ──
        $siteRow = $this->db->query("
            SELECT b.site_id FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
            WHERE d.employee_id = $employee_id ORDER BY d.date_time DESC LIMIT 1
        ")->fetch_assoc();
        $emp_site = $siteRow ? (int) $siteRow['site_id'] : 0;

        $batch = $this->db->query("
            SELECT id FROM DTR WHERE date_from <= '$date' AND date_to >= '$date'
            ORDER BY (site_id = $emp_site) DESC, id DESC LIMIT 1
        ")->fetch_assoc();

        if ($batch) {
            $ddtr_id = (int) $batch['id'];
        } else {
            // ── 3. Fallback: a one-day incident batch with a real site_id ──
            $site_id = $emp_site;
            if (!$site_id) {
                $bio = $this->db->query("SELECT site_id FROM employee_bio WHERE employee_id = $employee_id ORDER BY id DESC LIMIT 1")->fetch_assoc();
                $site_id = $bio ? (int) $bio['site_id'] : 0;
            }
            if (!$site_id) {
                $first = $this->db->query("SELECT id FROM sites ORDER BY id ASC LIMIT 1")->fetch_assoc();
                $site_id = $first ? (int) $first['id'] : 0;
            }
            if (!$site_id) throw new Exception('No site exists to attach the incident DTR to.');

            $site        = $this->db->query("SELECT employer_id FROM sites WHERE id = $site_id LIMIT 1")->fetch_assoc();
            $employer_id = $site ? (int) $site['employer_id'] : 1;
            $admin_row   = $this->db->query("SELECT id FROM users WHERE role = 1 LIMIT 1")->fetch_assoc();
            $admin_id    = $admin_row ? (int) $admin_row['id'] : 1;

            $found = $this->db->query("SELECT id FROM DTR WHERE date_from = '$date' AND site_id = $site_id AND device_id = 0 AND file = 'incident' LIMIT 1")->fetch_assoc();
            if ($found) {
                $ddtr_id = (int) $found['id'];
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO DTR (local_id, date_from, date_to, timekeeper_id, site_id, device_id, file, uploaded_by, employer_id, status)
                     VALUES (0, ?, ?, ?, ?, 0, 'incident', NULL, ?, 2)"
                );
                $stmt->bind_param('ssiii', $date, $date, $admin_id, $site_id, $employer_id);
                if (!$stmt->execute()) throw new Exception('Could not create the incident DTR batch: ' . $stmt->error);
                $ddtr_id = $this->db->insert_id;
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, overtime, late, undertime,
                                      day_type, nsd_hours, is_complete, logs, attendance_type, status,
                                      schedule_id, day_hours, is_rest_day, sched_start, sched_end, sched_break, sched_graveyard)
             VALUES (?,?,?,?,?,?,?,?,?,1,?,'incident',0,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('iisddddsdsidissii', $ddtr_id, $employee_id, $date, $work_hours, $overtime, $late, $undertime,
                          $day_type, $nsd_hours, $logs, $c['schedule_id'], $c['day_hours'], $c['is_rest_day'],
                          $c['sched_start'], $c['sched_end'], $c['sched_break'], $c['sched_graveyard']);
        if (!$stmt->execute()) throw new Exception('Could not write the DTR record: ' . $stmt->error);
    }

    // Writes an approved OT request's requested hours onto the matching DTR_details
    // row (same employee + date) so the timekeeper sees it filled in, not 0.
    // With no row for that date (rest-day OT / biometric import not run yet) the
    // hours are parked on a pending zero-hour row in the batch covering the date
    // so they aren't lost; its exception flags force a manual decision.
    // Returns false only when NO batch covers the date, so the caller can warn.
    private function applyOvertimeToDtr($req)
    {
        $employee_id = (int) $req['employee_id'];
        $date        = $this->db->real_escape_string($req['request_date']);
        $ot_hours    = (float) $req['ot_hours_requested'];

        $existing = $this->db->query(
            "SELECT id, is_rest_day FROM DTR_details WHERE employee_id = $employee_id AND date_time = '$date' ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();

        if ($existing) {
            // A REST-DAY filing authorizes the duty; it does not restate it.
            // Its hours name the whole day (7.5 on a 7.00 + 0.68 row), and the
            // row's work_hours are ALREADY paid — as a present day plus the 30%
            // rest-day premium — so writing 7.5 into `overtime` would pay the
            // same duty a second time at the OT rate. The scans keep deciding
            // the figures; approval only unblocks the record, and payroll still
            // caps what it pays at min(row.overtime, approved hours).
            if ((int) ($existing['is_rest_day'] ?? 0) === 1) return true;

            $existing_id = (int) $existing['id'];
            $stmt = $this->db->prepare("UPDATE DTR_details SET overtime = ? WHERE id = ?");
            $stmt->bind_param('di', $ot_hours, $existing_id);
            if (!$stmt->execute()) throw new Exception('Could not write the OT hours to the DTR: ' . $stmt->error);
            return true;
        }

        $batch = $this->db->query(
            "SELECT id FROM DTR WHERE date_from <= '$date' AND date_to >= '$date' ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();
        if (!$batch) return false;

        $ddtr_id = (int) $batch['id'];
        // Stamp the shift on the parked row so it follows the same frozen-shift
        // policy as every other new attendance row (no logs → figures stay 0).
        $cs = dtr_compute_day($this->db, $employee_id, $req['request_date'], []);
        // Same rule as above on a date that resolves to a rest day: the parked
        // row records the authorization, and the hours come from the scans when
        // they land — never from the filing.
        $park_hours = ((int) $cs['is_rest_day'] === 1) ? 0.0 : $ot_hours;
        $stmt = $this->db->prepare(
            "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, overtime, logs, attendance_type, status,
                                      schedule_id, day_hours, is_rest_day, sched_start, sched_end, sched_break, sched_graveyard)
             VALUES (?,?,?,0,?,'[]','overtime',0,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('iisdidissii', $ddtr_id, $employee_id, $date, $park_hours, $cs['schedule_id'], $cs['day_hours'], $cs['is_rest_day'],
                          $cs['sched_start'], $cs['sched_end'], $cs['sched_break'], $cs['sched_graveyard']);
        if (!$stmt->execute()) throw new Exception('Could not write the OT hours to the DTR: ' . $stmt->error);
        return true;
    }

    function delete_attendance_request()
    {
        $id   = intval($_POST['id'] ?? 0);
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!in_array($role, [1, 8, 9], true)) {
            return ['result' => false, 'message' => 'Not authorized'];
        }
        $stmt = $this->db->prepare("DELETE FROM attendance_requests WHERE id = ? AND status = 0");
        $stmt->bind_param('i', $id);
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Deleted'];
    }

    function save_position()
    {
        extract($_POST);

        $data = " name='$name' ";

        // $data .= ", department_id = '$department_id' ";

        if (empty($id)) {
            $this->db->query("INSERT INTO position set " . $data);
            return 1;
        } else {
            $this->db->query("UPDATE position set " . $data . " where id=" . $id);
            return 2;
        }
    }

    function delete_position()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM position where id = " . (int)$id);

        if ($delete) {
            return 1;
        }
    }

    function save_allowances()
    {
        extract($_POST);

        $data = " allowance='$allowance' ";

        $data .= ", description = '$description' ";

        if (empty($id)) {
            $save = $this->db->query("INSERT INTO allowances set " . $data);
        } else {
            $save = $this->db->query("UPDATE allowances set " . $data . " where id=" . $id);
        }

        if ($save) {
            return 1;
        }
    }

    function delete_allowances()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM allowances where id = " . (int)$id);

        if ($delete) {
            return 1;
        }
    }

    function save_employee_allowance()
    {
        extract($_POST);

        foreach ($allowance_id as $k => $v) {
            $data = " employee_id='$employee_id' ";

            $data .= ", allowance_id = '$allowance_id[$k]' ";

            $data .= ", type = '$type[$k]' ";

            $data .= ", amount = '$amount[$k]' ";

            $data .= ", effective_date = '$effective_date[$k]' ";

            $save[] = $this->db->query("INSERT INTO employee_allowances set " . $data);
        }

        if (isset($save)) {
            return 1;
        }
    }

    function delete_employee_allowance()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM employee_allowances where id = " . (int)$id);

        if ($delete) {
            return 1;
        }
    }

    function delete_employee_contribution()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM employee_contributions where id = " . (int)$id);

        if ($delete) {
            return 1;
        }
    }

    function save_deductions()
    {
        extract($_POST);

        $data = " deduction='$deduction' ";

        $data .= ", description = '$description' ";

        if (empty($id)) {
            $save = $this->db->query("INSERT INTO deductions set " . $data);
        } else {
            $save = $this->db->query("UPDATE deductions set " . $data . " where id=" . $id);
        }

        if ($save) {
            return 1;
        }
    }

    function delete_deductions()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM deductions where id = " . (int)$id);

        if ($delete) {
            return 1;
        }
    }

    function save_employee_deduction()
    {
        $employee_id  = (int)($_POST['employee_id'] ?? 0);
        $deduction_id = $_POST['deduction_id'] ?? [];
        $amount       = $_POST['amount'] ?? [];
        $total_amount = $_POST['total_amount'] ?? [];
        $effective    = $_POST['effective_date'] ?? [];
        $type         = $_POST['type'] ?? [];

        $save = [];
        foreach ($deduction_id as $k => $v) {
            $did   = (int)$v;
            $amt   = (float)($amount[$k] ?? 0);
            $total = (float)($total_amount[$k] ?? 0);
            $t     = isset($type[$k]) ? (int)$type[$k] : 1;
            // total_amount > 0 → amortizing deduction; opening balance = total.
            $balance = $total > 0 ? $total : 0;
            $edate = !empty($effective[$k])
                ? "'" . $this->db->real_escape_string($effective[$k]) . "'"
                : "NULL";
            $data = " employee_id = $employee_id, deduction_id = $did, type = $t,"
                . " amount = $amt, total_amount = $total, balance = $balance,"
                . " effective_date = $edate ";
            $save[] = $this->db->query("INSERT INTO employee_deductions set " . $data);
        }

        return !empty($save) ? 1 : 0;
    }

    function delete_employee_deduction()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM employee_deductions where id = " . (int)$id);

        if ($delete) {
            return 1;
        }
    }



    function delete_employee_attendance()
    {
        extract($_POST);
        $date = explode('_', $id);
        $dt = str_replace('"', "", $date[1]);
        $date_data = str_replace('"', "", $date[0]);
        $date_data = (int) $date_data;
        $delete = $this->db->query("DELETE FROM attendance where employee_id = '" . $date_data . "' and date(datetime_log) ='$dt' ");
        if ($delete) {
            return 1;
        }
    }

    function delete_employee_attendance_single()
    {
        extract($_POST);
        $delete = $this->db->query("DELETE FROM attendance where id = $id ");
        if ($delete) {
            return 1;
        }
    }


    function delete_payroll()
    {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id) return ['result' => false, 'message' => 'Invalid parameters'];

        // Never delete a LOCKED payroll — its loan deductions are committed and
        // would be stranded (balances decremented with no payroll to explain them).
        // The payroll must be Unlocked first, which reverses those deductions.
        $row = $this->db->query("SELECT status FROM payroll WHERE id = $id")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Payroll not found'];
        if ((int)$row['status'] === 2) {
            return ['result' => false, 'message' => 'Cannot delete a locked payroll. Unlock it first.'];
        }

        // Cascade-clean every child row so nothing is left orphaned.
        $this->db->begin_transaction();
        try {
            $this->db->query("DELETE FROM loan_history WHERE payroll_id = $id");
            $this->db->query("DELETE FROM deduction_history WHERE payroll_id = $id");
            // One-off items belong to the payroll — don't leave them orphaned.
            $this->db->query("DELETE FROM payroll_item_extras WHERE payroll_id = $id");
            $this->db->query("DELETE FROM payroll_items WHERE payroll_id = $id");
            $this->db->query("DELETE FROM payroll_employee_reviews WHERE payroll_id = $id");
            $this->db->query("DELETE FROM payroll_logs WHERE payroll_id = $id");
            $this->db->query("DELETE FROM payroll WHERE id = $id");
            $this->db->commit();
            return ['result' => true, 'message' => 'deleted'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    function delete_dtr()
    {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) return 0;

        // Biometric batches have no source file to re-import — deleting one
        // cascades to DTR_details and destroys the cutoff's raw punches for good.
        $batch = $this->db->query("SELECT file, status FROM DTR WHERE id = $id")->fetch_assoc();
        if (!$batch) return 0;
        if ($batch['file'] === 'biometric') {
            return json_encode(['result' => false, 'message' => 'Biometric batches cannot be deleted — their raw punches have no source file to restore from.']);
        }

        $delete = $this->db->query("DELETE FROM DTR where id = " . $id);

        if ($delete) {
            return 1;
        }
    }


    function save_contribution()
    {
        extract($_POST);

        // Validate and sanitize inputs
        $id = intval($id); // Ensure ID is an integer
        $amount = floatval($amount); // Ensure amount is a valid number

        if ($id <= 0 || $amount < 0) {
            return 0; // Invalid data
        }

        // Use prepared statements to prevent SQL injection
        $stmt = $this->db->prepare("UPDATE employee_contributions SET amount = ? WHERE id = ?");
        $stmt->bind_param("di", $amount, $id); // "di" means double, integer

        if ($stmt->execute()) {
            return 1; // Success
        } else {
            return 0; // Failure
        }
    }



    function save_time_logs()
    {
        extract($_POST);

        $hours_worked = abs(strtotime($end_date) - strtotime($start_date)) / 3600;

        $total_hours = number_format((float) $hours_worked, 2, '.', '');

        // $start_date1 = date('Y-m-d hh:mm', strtotime($start_date));

        // $end_date1 = date('Y-m-d hh:mm', strtotime($end_date));

        $start_date = DateTime::createFromFormat('Y-m-d h:i:s A', $start_date);
        $end_date = DateTime::createFromFormat('Y-m-d h:i:s A', $end_date);

        $start = $start_date->format('Y-m-d H:i:s');
        $end = $end_date->format('Y-m-d H:i:s');

        $data = "employee_id = '$employee_id' ";

        $data .= ", start_date = '$start' ";

        $data .= ", end_date = '$end' ";

        $data .= ", total_hours = '$total_hours' ";

        $data .= ", memo = '$memo' ";
        $save = $this->db->query("INSERT INTO time_logs set " . $data);

        if (isset($save)) {
            return 1;
        }
    }

    function delete_employee_timelogs()
    {
        extract($_POST);

        $delete = $this->db->query("DELETE FROM time_logs where id = " . $id);

        if ($delete) {
            return 1;
        }
    }

    function gel_all_employee()
    {
        $list = [];
        $query = $this->db->query("SELECT * FROM employee ");
        while ($row = $query->fetch_assoc()) {
            $list[] = $row;
        }
        return $list;
    }

    function filter_attendance()
    {
        extract($_POST);
        $_SESSION['attendance_from'] = $from;
        $_SESSION['attendance_to'] = $to;
        return 1;
    }

    function save_site()
    {
        try {
            // Ensure all expected keys exist
            $site_name     = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
            $site_address  = isset($_POST['site_address']) ? trim($_POST['site_address']) : '';
            $employer_id   = isset($_POST['employer_id']) ? $_POST['employer_id'] : '';
            $cluster_id    = isset($_POST['cluster_id']) ? $_POST['cluster_id'] : '';
            $site_code     = isset($_POST['site_code']) ? trim($_POST['site_code']) : '';
            $timekeeper_id = isset($_POST['timekeeper_id']) ? $_POST['timekeeper_id'] : '';
            $pic           = isset($_POST['pic']) ? trim($_POST['pic']) : '';
            $id            = isset($_POST['id']) ? $_POST['id'] : '';
            $status        = isset($_POST['status']) ? 1 : 0;

            // Sanitize inputs
            $site_name     = mysqli_real_escape_string($this->db, $site_name);
            $site_address  = mysqli_real_escape_string($this->db, $site_address);
            $employer_id   = mysqli_real_escape_string($this->db, $employer_id);
            $cluster_id    = mysqli_real_escape_string($this->db, $cluster_id);
            $site_code     = mysqli_real_escape_string($this->db, $site_code);
            $timekeeper_id = mysqli_real_escape_string($this->db, $timekeeper_id);
            $pic           = mysqli_real_escape_string($this->db, $pic);
            $id            = mysqli_real_escape_string($this->db, $id);

            // Basic validation
            if (empty($site_name) || empty($employer_id)) {
                return [
                    'status'  => "success",
                    'message' => 'Site name and employer are required.'
                ];
            }

            // Build SQL data string
            $data = "
            site_name = '$site_name',
            site_address = '$site_address',
            employer_id = '$employer_id',
            cluster_id = '$cluster_id',
            site_code = '$site_code',
            status = '$status',
            timekeeper_id = '$timekeeper_id'
        ";

            // Insert or update
            if (empty($id)) {
                $save = $this->db->query("INSERT INTO sites SET $data");
                if (!$save) {
                    throw new Exception("Insert failed: " . $this->db->error);
                }

                $new_id = $this->db->insert_id;
                if (!empty($timekeeper_id)) {
                    $this->db->query("UPDATE users SET site_id = '$new_id' WHERE id = '$timekeeper_id'");
                }

                return [
                    'status'  => "success",
                    'message' => 'Site created successfully.'
                ];
            } else {
                $save = $this->db->query("UPDATE sites SET $data WHERE id = '$id'");
                if (!$save) {
                    throw new Exception("Update failed: " . $this->db->error);
                }

                if (!empty($timekeeper_id)) {
                    $this->db->query("UPDATE users SET site_id = '$id' WHERE id = '$timekeeper_id'");
                }

                return [
                    'status'  => "success",
                    'message' => 'Site updated successfully.'
                ];
            }
        } catch (Exception $e) {
            // Catch and return any error message
            return [
                'status'  => "error",
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }


    function save_user()
    {
        try {
            // Enable MySQLi exceptions for try/catch
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            // Gather POST data safely
            $name         = isset($_POST['name']) ? $_POST['name'] : '';
            $username     = isset($_POST['username']) ? $_POST['username'] : '';
            $password     = isset($_POST['password']) ? $_POST['password'] : '';
            $role         = isset($_POST['role']) ? $_POST['role'] : '';
            $site_id      = isset($_POST['site_id']) ? $_POST['site_id'] : '';
            $department_id = isset($_POST['department_id']) ? $_POST['department_id'] : '';
            $employer_id  = isset($_POST['employer_id']) ? $_POST['employer_id'] : '';
            $id           = isset($_POST['id']) ? $_POST['id'] : '';

            // Sanitize inputs
            $name        = mysqli_real_escape_string($this->db, $name);
            $username    = mysqli_real_escape_string($this->db, $username);
            $role        = mysqli_real_escape_string($this->db, $role);
            $site_id     = mysqli_real_escape_string($this->db, $site_id);
            $department_id = mysqli_real_escape_string($this->db, $department_id);
            $employer_id = mysqli_real_escape_string($this->db, $employer_id);
            $id          = mysqli_real_escape_string($this->db, $id);

            // A Department Head (8) and a Supervisor (10) must each be tied to a
            // department (used later to approve that department's leave requests).
            if (in_array((int)$role, [8, 10], true) && $department_id === '') {
                $label = ((int)$role === 10) ? 'Supervisor' : 'Department Head';
                return ['result' => false, 'message' => "Please select a department for the $label."];
            }

            // Only ONE active Supervisor is allowed per department. Block creating
            // (or switching a user into) a second Supervisor for the same dept.
            if ((int)$role === 10 && $department_id !== '') {
                $self = $id !== '' ? " AND id <> '$id'" : '';
                $dupe = $this->db->query(
                    "SELECT id FROM users WHERE role = 10 AND status = 1
                     AND department_id = '$department_id'$self LIMIT 1"
                );
                if ($dupe && $dupe->num_rows > 0) {
                    return ['result' => false, 'message' => 'This department already has a Supervisor. Only one Supervisor per department is allowed.'];
                }
            }

            // Handle password hashing and query part
            $password_sql = '';

            if (empty($id)) {
                // New user
                if (empty($password)) {
                    return ['result' => false, 'message' => 'Password is required for new users.'];
                }
                $password = password_hash(mysqli_real_escape_string($this->db, $password), PASSWORD_BCRYPT);
                $password_sql = ", password = '$password'";
            } else {
                // Existing user — update password only if provided
                if (!empty($password)) {
                    $password = password_hash(mysqli_real_escape_string($this->db, $password), PASSWORD_BCRYPT);
                    $password_sql = ", password = '$password'";
                }
            }

            // Check duplicate username only for new users
            if (empty($id)) {
                $check_username = $this->db->query("SELECT id FROM users WHERE username = '$username' LIMIT 1");
                if ($check_username->num_rows > 0) {
                    return ['result' => false, 'message' => 'Username already exists!'];
                }
            }

            // Build data string
            $data = "
            name = '$name',
            username = '$username',
            role = '$role',
            employer_id = '$employer_id'
            $password_sql
        ";

            if (!empty($site_id)) {
                $data .= ", site_id = '$site_id'";
            }

            // Store the department for a Department Head (8) or Supervisor (10);
            // clear it for any other role.
            $data .= ", department_id = " . (in_array((int)$role, [8, 10], true) && $department_id !== '' ? "'$department_id'" : "NULL");

            // Which employee this login is. Only meaningful for the roles that
            // approve leave — it is what keeps a head from being offered their
            // own request. One employee may hold only one login, or the
            // "is this me?" test downstream would have two answers.
            $emp_link = 'NULL';
            if (in_array((int)$role, [8, 9, 10, 11], true) && !empty($_POST['employee_id'])) {
                $eid  = (int) $_POST['employee_id'];
                $self = $id !== '' ? " AND id <> '$id'" : '';
                $ex   = $this->db->query("SELECT id FROM users WHERE employee_id = $eid$self LIMIT 1");
                if ($ex && $ex->num_rows > 0) {
                    return ['result' => false, 'message' => 'That employee is already linked to another user account.'];
                }
                $ok = $this->db->query("SELECT id FROM employee WHERE id = $eid");
                if ($ok && $ok->num_rows > 0) $emp_link = (string) $eid;
            }
            $data .= ", employee_id = $emp_link";

            // Insert or update user
            if (empty($id)) {
                $save = $this->db->query("INSERT INTO users SET $data");
                $user_id = $this->db->insert_id;
            } else {
                $save = $this->db->query("UPDATE users SET $data WHERE id = '$id'");
                $user_id = $id;
            }

            // Optional: update related site for timekeeper role
            if ($role == '5') {
                $this->db->query("UPDATE sites SET timekeeper_id = '$user_id' WHERE id = '$site_id'");
            }

            // Success response
            if ($save) {
                return [
                    'result' => true,
                    'message' => empty($id) ? 'User created successfully!' : 'User updated successfully!'
                ];
            }
        } catch (mysqli_sql_exception $e) {
            // Database errors (e.g., constraint violations, SQL syntax issues)
            return [
                'result' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            // Other unexpected PHP errors
            return [
                'result' => false,
                'message' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }



    function localSync()
    {
        $result = [
            'employees' => [],

        ];

        // Query the department table
        $query = $this->db->query("SELECT * FROM department");
        while ($row = $query->fetch_assoc()) {
            $result['departments'][] = $row;
        }

        // Query the position table
        $query = $this->db->query("SELECT * FROM position");
        while ($row = $query->fetch_assoc()) {
            $result['positions'][] = $row;
        }

        // Query the employee table
        $query = $this->db->query("SELECT e.id,e.department_id,e.position_id, e.employee_no, e.firstname, e.middlename, e.lastname, e.salary, e.ot_rate, e.status, e.weekly_payroll, d.name as department, p.name as position FROM employee e 
        LEFT JOIN department d ON e.department_id = d.id 
        LEFT JOIN position p ON e.position_id = p.id "); //WHERE e.id = 27
        while ($row = $query->fetch_assoc()) {
            $result['employees'][] = $row;
        }


        // Return the structured result
        return $result;
    }

    function loginMobile1()
    {
        extract($_POST);
        // Prepare the SQL statement with parameters
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        if ($stmt) {
            // Bind parameters and execute query
            $stmt->bind_param('s', $username);
            $stmt->execute();

            // Get the result
            $result = $stmt->get_result();

            // Check if a row was found
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Password correct, return user ID or other identifier
                    return $user['id']; // Assuming 'id' is the primary key of the users table
                } else {
                    // Password incorrect
                    return false;
                }
            } else {
                // No user found with the given username
                return false;
            }

            // Close the statement
            $stmt->close();
        } else {
            // Error preparing statement
            return false;
        }
    }

    function loginMobile()
    {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $status = 1;
            $inputJSON = file_get_contents('php://input');
            $input = json_decode($inputJSON, true);

            if ($input === null || !isset($input['username']) || !isset($input['password'])) {
                return ['result' => false, 'message' => 'Invalid data'];
            }

            $username = $input['username'];
            $password = $input['password'];

            // Fetch active user
            $stmt = $this->db->prepare("
            SELECT users.*, employers.employer_name
            FROM users
            LEFT JOIN employers ON employers.id = users.employer_id
            WHERE username = ? AND users.status = ?
        ");
            $stmt->bind_param('ss', $username, $status);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows !== 1) {
                return ['result' => false, 'message' => 'No user found with the given username'];
            }

            $user = $result->fetch_assoc();
            $stored_hashed_password = $user['password'];

            if (!password_verify($password, $stored_hashed_password)) {
                return ['result' => false, 'message' => 'Password incorrect'];
            }

            // ✅ Only return ACTIVE sites assigned to this user
            $timekeeper_id = $user['id'];
            $qry_sites = $this->db->query("
            SELECT sites.*, clusters.cluster 
            FROM sites  
            LEFT JOIN clusters ON sites.cluster_id = clusters.id
            WHERE sites.timekeeper_id = '$timekeeper_id' AND sites.status = 1
        ");

            $sites = [];
            while ($site_row = $qry_sites->fetch_assoc()) {
                $sites[] = $site_row;
            }

            if (count($sites) === 0) {
                return ['result' => false, 'message' => 'No active sites assigned to you.'];
            }

            return [
                'result' => true,
                'user'   => $user,
                'sites'  => $sites,
            ];
        } catch (mysqli_sql_exception $e) {
            return ['result' => false, 'message' => 'Database error: ' . $e->getMessage()];
        } catch (Exception $e) {
            return ['result' => false, 'message' => 'Unexpected error: ' . $e->getMessage()];
        }
    }



    function save_employee_attendance_manual()
    {
        $post = $_POST;
        $date_from =  date("Y-m-d", strtotime($post['dtr']['date_from']));
        $date_to =  date("Y-m-d", strtotime($post['dtr']['date_to']));
        $timekeeper_id =  $post['dtr']['timekeeper_id'];
        $site_id =   $post['dtr']['site_id'];
        $device_id = $post['dtr']['device_id'];
        $file =  $post['dtr']['file'];
        $local_id = $post['dtr']['id'];
        $dtr_details = $post['dtr_details'];
        $qry = $this->db->query("SELECT * FROM users WHERE id = '$timekeeper_id' AND role = 5 ");
        $user_data = $qry->fetch_assoc();
        // $site_id = $user_data['site_id'];
        $employer_id = $user_data['employer_id'];
        $qry_exist = $this->db->query("SELECT * FROM DTR WHERE date_from = '$date_from' AND date_to = '$date_to' AND site_id = '$site_id'  LIMIT 1 ");
        if ($qry_exist->num_rows > 0) {
            return ['result' => false, 'message' => 'DTR date already exist'];
        }

        $qry_site = $this->db->query("SELECT * FROM sites WHERE id = '$site_id' AND  status = 1 ");
        if ($qry_site->num_rows === 0) {
            return ['result' => false, 'message' => 'Site is inactive'];
        }

        $qry_site_2 = $this->db->query("SELECT * FROM sites WHERE timekeeper_id = '$timekeeper_id' ");
        if ($qry_site_2->num_rows === 0) {
            return ['result' => false, 'message' => "You're not currently assigned to this site. Please log in again."];
        }

        $qry_site_2 = $this->db->query("SELECT COUNT(*) AS total_sites FROM sites WHERE timekeeper_id = '$timekeeper_id' AND status = 1");
        if ($qry_site_2->num_rows > 0) {
            $row_site = $qry_site_2->fetch_assoc();
            if ($row_site['total_sites'] > 1) {
                return ['result' => false, 'message' => "Too many sites are currently assigned. Please contact the administrator for assistance."];
            }
        }

        $this->db->begin_transaction();
        try {

            if ($qry->num_rows == 0) {
                throw new Exception('User not found');
            }
            $sql = "INSERT INTO DTR (local_id, date_from, date_to, timekeeper_id, site_id, device_id, file, uploaded_by, employer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?,?)";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('sssssssss', $local_id, $date_from, $date_to, $timekeeper_id, $site_id, $device_id, $file, $_SESSION['login_id'], $employer_id);
            $stmt->execute();
            $ddtr_id = '';
            if ($stmt->affected_rows == 0) {
                throw new Exception('Failed to insert data');
            } else {
                $ddtr_id = $this->db->insert_id;
            }
            foreach ($dtr_details as $k) {
                $employee_id = $k['employee_id'];
                $attendance_type = $k['type'];
                $logs = $k['logs'];
                $hours = $k['hours'] > 8 ? 8 : $k['hours'];
                $overtime = $k['ot'];
                $date_time = $k['date_time'];
                $code = $k['code'];
                $qry_bio = $this->db->query("SELECT * FROM employee_bio  WHERE employee_id = '$employee_id' AND site_id = '$site_id' AND device_id = '$device_id'
                 LIMIT 1 ");
                if ($qry_bio->num_rows == 0) {
                    $sql2 = "INSERT INTO employee_bio (employee_id, device_id, site_id, code) VALUES (?, ?, ?, ?)";
                    $stmtbio = $this->db->prepare($sql2);
                    $stmtbio->bind_param('ssss', $employee_id, $device_id, $site_id, $code);
                    try {
                        $stmtbio->execute();
                    } catch (Exception $e) {
                        throw new Exception('Failed to insert data');
                    }
                }

                // Stamp the shift like biometric ingestion does, so uploaded rows
                // follow the same frozen-shift policy instead of drifting with
                // later roster edits.
                $cs = dtr_compute_day($this->db, (int)$employee_id, date('Y-m-d', strtotime($date_time)), []);
                $sql2 = "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, overtime,
                                                  schedule_id, day_hours, is_rest_day, sched_start, sched_end, sched_break, sched_graveyard)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt2 = $this->db->prepare($sql2);
                $stmt2->bind_param('sssssssidissii', $ddtr_id, $employee_id, $date_time, $hours, $logs, $attendance_type, $overtime,
                                   $cs['schedule_id'], $cs['day_hours'], $cs['is_rest_day'],
                                   $cs['sched_start'], $cs['sched_end'], $cs['sched_break'], $cs['sched_graveyard']);
                try {
                    $stmt2->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to insert data');
                }
            }
            $this->db->commit();
            return ['result' => true, 'message' => 'Data inserted successfully', 'id' => $this->db->insert_id]; //

        } catch (Exception $e) {
            $this->db->rollback(); // Rollback on errors
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    function save_employee_attendance_mobile()
    {
        $post = json_decode(file_get_contents('php://input'), true);
        $date_from =  date("Y-m-d", strtotime($post['dtr']['date_from']));
        $date_to =  date("Y-m-d", strtotime($post['dtr']['date_to']));
        $timekeeper_id =  $post['timekeeper_id'];
        $site_id =  $post['site_id'];

        $device_id = $post['dtr']['device_id'];
        $file =  $post['dtr']['file'];
        $local_id = $post['dtr']['id'];
        $dtr_details = $post['dtr_details'];
        $ptype = $post['dtr']['weekly_payroll']; //needd to fixed this from mobile
        $qry = $this->db->query("SELECT * FROM users WHERE id = '$timekeeper_id' AND role = 5 ");
        $user_data = $qry->fetch_assoc();
        // $site_id = $user_data['site_id'];
        $employer_id = $user_data['employer_id'];
        $qry_exist = $this->db->query("SELECT * FROM DTR WHERE date_from = '$date_from' AND date_to = '$date_to' AND site_id = '$site_id'   AND ptype='$ptype' LIMIT 1 ");
        if ($qry_exist->num_rows > 0) {
            return ['result' => false, 'message' => 'DTR date already exist'];
        }
        $qry_site = $this->db->query("SELECT * FROM sites WHERE id = '$site_id' AND  status = 1 ");
        if ($qry_site->num_rows === 0) {
            return ['result' => false, 'message' => 'Site is inactive'];
        }

        $qry_site_2 = $this->db->query("SELECT * FROM sites WHERE timekeeper_id = '$timekeeper_id' ");
        if ($qry_site_2->num_rows === 0) {
            return ['result' => false, 'message' => "You're not currently assigned to this site. Please log in again."];
        }

        // $qry_site_2 = $this->db->query("SELECT COUNT(*) AS total_sites FROM sites WHERE timekeeper_id = '$timekeeper_id' AND status = 1");
        // if ($qry_site_2->num_rows > 0) {
        //     $row_site = $qry_site_2->fetch_assoc();
        //     if ($row_site['total_sites'] > 1) {
        //         return ['result' => false, 'message' => "Too many sites are currently assigned. Please contact the administrator for assistance."];
        //     }
        // }

        $this->db->begin_transaction();
        try {
            if ($qry->num_rows == 0) {
                throw new Exception('User not found'); // Throw exception for rollback
            }

            $sql = "INSERT INTO DTR (local_id, date_from, date_to, timekeeper_id, site_id, device_id, file, uploaded_by, employer_id, ptype ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('ssssssssss', $local_id, $date_from, $date_to, $timekeeper_id, $site_id, $device_id, $file,  $timekeeper_id, $employer_id, $ptype);
            $stmt->execute();
            $ddtr_id = '';
            if ($stmt->affected_rows == 0) {
                throw new Exception('Failed to insert data'); // Throw exception for rollback
            } else {
                $ddtr_id = $this->db->insert_id; // Assuming $this->db is your connection object
            }

            foreach ($dtr_details as $k) {
                $employee_id = $k['employee_id'];
                $attendance_type = $k['type'];
                $logs = $k['logs'];
                $hours = $k['hours']  > 8 ? 8 : $k['hours'];
                $overtime = $k['ot'];
                $notes = $k['notes'];
                $date_time = $k['date_time'];
                $code = $k['code'];
                $qry_bio = $this->db->query("SELECT * FROM employee_bio  WHERE employee_id = '$employee_id' AND site_id = '$site_id' AND device_id = '$device_id'
                 LIMIT 1 ");
                if ($qry_bio->num_rows == 0) {
                    $sql2 = "INSERT INTO employee_bio (employee_id, device_id, site_id, code) VALUES (?, ?, ?, ?)";
                    $stmtbio = $this->db->prepare($sql2);
                    $stmtbio->bind_param('ssss', $employee_id, $device_id, $site_id, $code);
                    try {
                        $stmtbio->execute();
                    } catch (Exception $e) {
                        throw new Exception('Failed to insert data');
                    }
                }
                // Same frozen-shift stamp the web upload and biometric ingestion apply.
                $cs = dtr_compute_day($this->db, (int)$employee_id, date('Y-m-d', strtotime($date_time)), []);
                $sql2 = "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, overtime, notes,
                                                  schedule_id, day_hours, is_rest_day, sched_start, sched_end, sched_break, sched_graveyard)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt2 = $this->db->prepare($sql2);
                $stmt2->bind_param('ssssssssidissii', $ddtr_id, $employee_id, $date_time, $hours, $logs, $attendance_type, $overtime, $notes,
                                   $cs['schedule_id'], $cs['day_hours'], $cs['is_rest_day'],
                                   $cs['sched_start'], $cs['sched_end'], $cs['sched_break'], $cs['sched_graveyard']);
                try {
                    $stmt2->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to insert data');
                }
            }

            $this->db->commit();
            return ['result' => true, 'message' => 'Data inserted successfully', 'id' => $this->db->insert_id]; //

        } catch (Exception $e) {
            $this->db->rollback(); // Rollback on errors
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }


    ////https://chatgpt.com/c/67bd10fb-c7f8-800f-9ed4-c429be8e50fe
    function getSSSMonthlyDeduction($monthly_salary)
    {
        // Define the 2025 SSS Contribution Table (MSC brackets and EE share)
        $sss_brackets = [
            ["range" => [5000, 5499.99], "monthly_employee" => 250],
            ["range" => [5500, 5999.99], "monthly_employee" => 275],
            ["range" => [6000, 6499.99], "monthly_employee" => 300],
            ["range" => [6500, 6999.99], "monthly_employee" => 325],
            ["range" => [7000, 7499.99], "monthly_employee" => 350],
            ["range" => [7500, 7999.99], "monthly_employee" => 375],
            ["range" => [8000, 8499.99], "monthly_employee" => 400],
            ["range" => [8500, 8999.99], "monthly_employee" => 425],
            ["range" => [9000, 9499.99], "monthly_employee" => 450],
            ["range" => [9500, 9999.99], "monthly_employee" => 475],
            ["range" => [10000, 10499.99], "monthly_employee" => 500],
            ["range" => [10500, 10999.99], "monthly_employee" => 525],
            ["range" => [11000, 11499.99], "monthly_employee" => 550],
            ["range" => [11500, 11999.99], "monthly_employee" => 575],
            ["range" => [12000, 12499.99], "monthly_employee" => 600],
            ["range" => [12500, 12999.99], "monthly_employee" => 625],
            ["range" => [13000, 13499.99], "monthly_employee" => 650],
            ["range" => [13500, 13999.99], "monthly_employee" => 675],
            ["range" => [14000, 14499.99], "monthly_employee" => 700],
            ["range" => [14500, 14999.99], "monthly_employee" => 725],
            ["range" => [15000, 15499.99], "monthly_employee" => 750],
            ["range" => [15500, 15999.99], "monthly_employee" => 775],
            ["range" => [16000, 16499.99], "monthly_employee" => 800],
            ["range" => [16500, 16999.99], "monthly_employee" => 825],
            ["range" => [17000, 17499.99], "monthly_employee" => 850],
            ["range" => [17500, 17999.99], "monthly_employee" => 875],
            ["range" => [18000, 18499.99], "monthly_employee" => 900],
            ["range" => [18500, 18999.99], "monthly_employee" => 925],
            ["range" => [19000, 19499.99], "monthly_employee" => 950],
            ["range" => [19500, 19999.99], "monthly_employee" => 975],
            ["range" => [20000, 20499.99], "monthly_employee" => 1000],
            ["range" => [20500, 20999.99], "monthly_employee" => 1025],
            ["range" => [21000, 21499.99], "monthly_employee" => 1050],
            ["range" => [21500, 21999.99], "monthly_employee" => 1075],
            ["range" => [22000, 22499.99], "monthly_employee" => 1100],
            ["range" => [22500, 22999.99], "monthly_employee" => 1125],
            ["range" => [23000, 23499.99], "monthly_employee" => 1150],
            ["range" => [23500, 23999.99], "monthly_employee" => 1175],
            ["range" => [24000, 24499.99], "monthly_employee" => 1200],
            ["range" => [24500, 24999.99], "monthly_employee" => 1225],
            ["range" => [25000, 25499.99], "monthly_employee" => 1250],
            ["range" => [25500, 25999.99], "monthly_employee" => 1275],
            ["range" => [26000, 26499.99], "monthly_employee" => 1300],
            ["range" => [26500, 26999.99], "monthly_employee" => 1325],
            ["range" => [27000, 27499.99], "monthly_employee" => 1350],
            ["range" => [27500, 27999.99], "monthly_employee" => 1375],
            ["range" => [28000, 28499.99], "monthly_employee" => 1400],
            ["range" => [28500, 28999.99], "monthly_employee" => 1425],
            ["range" => [29000, 29499.99], "monthly_employee" => 1450],
            ["range" => [29500, 29999.99], "monthly_employee" => 1475],
            ["range" => [30000, 34999.99], "monthly_employee" => 1500],
            ["range" => [35000, PHP_INT_MAX], "monthly_employee" => 1750] // Maximum MSC
        ];

        // Find the appropriate bracket
        foreach ($sss_brackets as $bracket) {
            if ($monthly_salary >= $bracket["range"][0] && $monthly_salary <= $bracket["range"][1]) {
                return $bracket["monthly_employee"];
            }
        }

        // Default return 0 if no bracket is matched
        return 0;
    }

    function calculatePhilHealth($monthly_salary)
    {
        // Minimum and Maximum Salary Brackets
        $min_salary = 12000;
        $max_salary = 50000;
        $rate = 0.05; // 5% PhilHealth rate
        $max_contribution = 1250; // Max contribution cap at ₱50,000

        // If salary is below the minimum, apply the lowest contribution
        if ($monthly_salary <= $min_salary) {
            return 300; // ₱12,000 salary = ₱300 PhilHealth
        }

        // If salary is above the maximum, apply the highest contribution
        if ($monthly_salary >= $max_salary) {
            return $max_contribution;
        }

        // Compute PhilHealth Contribution: (Salary × 5%) ÷ 2 (shared by employer & employee)
        $contribution = ($monthly_salary * $rate) / 2;

        return round($contribution, 2);
    }


    function getSSSWeeklyDeduction($weekly_salary)
    {
        // Define the updated 2025 MSC brackets and employee contributions
        $sss_brackets = [
            ["range" => [7800, 8499.99], "monthly_employee" => 400],
            ["range" => [8500, 8999.99], "monthly_employee" => 425],
            ["range" => [9000, 9499.99], "monthly_employee" => 450],
            ["range" => [9500, 9999.99], "monthly_employee" => 475],
            ["range" => [10000, 10499.99], "monthly_employee" => 500],
            ["range" => [10500, 10999.99], "monthly_employee" => 525],
            ["range" => [11000, 11499.99], "monthly_employee" => 550],
            ["range" => [11500, 11999.99], "monthly_employee" => 575],
            ["range" => [12000, PHP_INT_MAX], "monthly_employee" => 600]
        ];

        // Convert weekly salary to monthly equivalent (assuming 4.33 weeks in a month)
        $monthly_salary = $weekly_salary; //* 4.33

        // Find the appropriate bracket
        foreach ($sss_brackets as $bracket) {
            if ($monthly_salary >= $bracket["range"][0] && $monthly_salary <= $bracket["range"][1]) {
                // Convert monthly employee share to weekly
                return round($bracket["monthly_employee"] / 4.33, 2);
            }
        }

        // Default return 0 if no bracket is matched
        return 0;
    }

    function calculatePhilHealthWeekly($monthly_salary)
    {
        // Define 2024 PhilHealth Rates
        $rate = 0.05; // 5% total contribution
        $employee_share = 0.025; // 2.5% EE share
        $employer_share = 0.025; // 2.5% ER share
        $min_salary = 10000; // Minimum salary for PhilHealth
        $max_salary = 100000; // Maximum salary cap for PhilHealth

        // Apply salary limits
        if ($monthly_salary < $min_salary) {
            $monthly_salary = $min_salary; // Apply minimum base salary
        } elseif ($monthly_salary > $max_salary) {
            $monthly_salary = $max_salary; // Apply maximum base salary
        }

        // Compute Contributions
        $total_contribution = $monthly_salary * $rate; // 5% of salary
        $ee_contribution = $total_contribution * $employee_share / $rate; // 2.5%
        $er_contribution = $total_contribution * $employer_share / $rate; // 2.5%
        return $total_contribution;
        // Return results as an array
        // return [
        //     'total' => round($total_contribution, 2),
        //     'ee' => round($ee_contribution, 2),
        //     'er' => round($er_contribution, 2)
        // ];
    }

    //  calcute tax https://chatgpt.com/c/67c55173-83e0-800f-b6a2-58fa42f159db
    function calculate_payroll()
    {
        $id = $this->db->real_escape_string($_POST['id']);
        $type = isset($_POST['type']) ? $this->db->real_escape_string($_POST['type']) : '';
        $recalculate = !empty($type);
        $pay = $this->db->query("SELECT * FROM payroll where id = " . $id)->fetch_array();
        // Block recalculation of a locked payroll — it must be Unlocked first
        // (which reverses the committed loan deductions). Fresh auto-calc on
        // create passes no $type, so this only guards explicit recalculates.
        if ($recalculate && (int)$pay['status'] === 2) {
            return ['result' => false, 'message' => 'Cannot recalculate a locked payroll. Unlock it first.'];
        }
        $week = $this->db->real_escape_string($pay['type']);
        $this->db->begin_transaction(); // Start transaction
        $site_ids_string = $pay['site_ids'];
        // Weekly payroll was removed: runs no longer partition employees by a
        // weekly/semi-monthly flag, so every employee with approved DTR in the
        // period and site is included regardless of payroll type.
        $site_ids = json_decode($site_ids_string, true);
        $commaSeparatedSites = implode(',', $site_ids);
        // A freshly created payroll has no settings yet (settings are chosen via
        // the Payroll Settings modal). Default to an empty array so the deduction
        // loop below doesn't warn / crash on null.
        $settings = json_decode($pay['settings'], true);
        if (!is_array($settings)) $settings = [];

        // Columns no part of the calculation writes — they exist only because an
        // admin typed them into the payroll table. The rebuild below DELETEs every
        // item row, so each recalculate used to reset them to the column defaults:
        // allowances back to zero days, adjustments and hand-entered deductions
        // gone, with nothing on screen to say it had happened. Snapshot by
        // employee (item ids don't survive the rebuild) and restore after.
        // Holiday days are deliberately NOT carried over: they are computed from
        // the holiday calendar now, so the fresh count must win.
        $manualKeep = [];

        if ($recalculate) {
            $mq = $this->db->query(
                "SELECT employee_id, allowance_days, jei_advances, jcc_advances, tax,
                        COALESCE(tax_override, 0) AS tax_override,
                        other_deduction, adjustment, adjustment_remarks
                 FROM payroll_items WHERE payroll_id = " . $id
            );
            if ($mq) {
                while ($m = $mq->fetch_assoc()) {
                    // Nothing typed on this row → nothing worth restoring.
                    if (!(float)$m['allowance_days'] && !(float)$m['jei_advances']
                        && !(float)$m['jcc_advances'] && !(float)$m['tax']
                        && !(float)$m['other_deduction'] && !(float)$m['adjustment']
                        && trim((string)$m['adjustment_remarks']) === '') {
                        continue;
                    }
                    $manualKeep[(int)$m['employee_id']] = $m;
                }
            }

            $this->db->query("DELETE FROM payroll_items where payroll_id = " . $id);
            $this->db->query("DELETE FROM loan_history where payroll_id = " . $id);
            $this->save_payroll_history($id, 3);
        } else {
            $this->save_payroll_history($id, 2);
        }


        try {
            // Classifications excluded from payroll (e.g. Interns) — global setting.
            $excluded_clasif = "'" . implode("','", array_map([$this->db, 'real_escape_string'], PAYROLL_EXCLUDED_CLASSIFICATIONS)) . "'";
            $exclude_clause = " AND employee.clasification_id NOT IN (SELECT id FROM clasification WHERE UPPER(clasification) IN ($excluded_clasif)) ";

            // Construct the SQL query with the site IDs directly included
            $sql = "SELECT DTR_details.*, employee.salary, employee.allowance_rate, employee.sss_fund, employee.basic_pay, employee.rate_type, employee.ot_rate,employee.isAutoDeduct, employee.loan_id, employee.loan_deduction, employee.loan, DTR.site_id
                FROM DTR_details
                INNER JOIN DTR ON DTR.id = DTR_details.ddtr_id
                INNER JOIN employee ON  DTR_details.employee_id = employee.id
                WHERE date(DTR_details.date_time) BETWEEN ? AND ?  AND DTR.status = 2
                AND DTR_details.status = 1
                AND DTR.site_id IN ($commaSeparatedSites) $exclude_clause";

            $stmt = $this->db->prepare($sql);
            // Bind the date parameters only
            $date_from = date("Y-m-d", strtotime($pay['date_from']));
            $date_to = date("Y-m-d", strtotime($pay['date_to']));

            // contribution_id → name, so the per-employee loop can tell which
            // rows are SSS / PhilHealth / Pag-IBIG and compute them from the
            // statutory schedule instead of using the stored flat amount.
            // Fetched once rather than per employee per contribution.
            $contribution_names = [];
            $cnq = $this->db->query("SELECT id, contribution FROM contributions");
            if ($cnq) while ($cn = $cnq->fetch_assoc()) {
                $contribution_names[(int) $cn['id']] = $cn['contribution'];
            }

            // ── Configured allowances per employee ──────────────────────────
            // employee_allowances + allowances have existed (with a management
            // screen) since before this branch, but only the dead legacy
            // calculate_payrollOld() ever read them — the live calculation used
            // the single flat employee.allowance_rate. Prefetched once here and
            // resolved per run below, so a recurring allowance is defined once
            // per employee instead of being retyped into `adjustment` every
            // cutoff. Guarded on the table existing so an un-migrated install
            // simply gets no configured allowances.
            $employee_allowances = [];
            if (($eah = $this->db->query("SHOW TABLES LIKE 'employee_allowances'")) && $eah->num_rows) {
                $has_eff_to = ($c2 = $this->db->query("SHOW COLUMNS FROM employee_allowances LIKE 'effective_to'")) && $c2->num_rows;
                $eff_to_col = $has_eff_to ? 'ea.effective_to' : 'NULL AS effective_to';
                // Taxability travels with the allowance so the withholding base
                // can be derived from the frozen item alone — a de minimis meal
                // allowance and a taxable job allowance are indistinguishable
                // once blended into a single figure.
                $has_tax_col = ($c3 = $this->db->query("SHOW COLUMNS FROM allowances LIKE 'is_taxable'")) && $c3->num_rows;
                $tax_cols = $has_tax_col
                    ? 'a.is_taxable, a.de_minimis_monthly_cap'
                    : '1 AS is_taxable, NULL AS de_minimis_monthly_cap';
                $eaq = $this->db->query(
                    "SELECT ea.employee_id, ea.allowance_id, ea.type, ea.amount,
                            ea.effective_date, $eff_to_col,
                            a.allowance AS label, $tax_cols
                       FROM employee_allowances ea
                       LEFT JOIN allowances a ON a.id = ea.allowance_id"
                );
                if ($eaq) while ($ea = $eaq->fetch_assoc()) {
                    $employee_allowances[(int) $ea['employee_id']][] = $ea;
                }
            }

            $stmt->bind_param("ss", $date_from, $date_to);
            $stmt->execute();
            $result = $stmt->get_result();
            // Snapshot the DTR row count NOW — the per-employee settings loop
            // below reuses $result for contribution/deduction/loan lookups, so
            // by the final success check $result no longer holds the DTR set.
            $dtr_count = $result->num_rows;
            if ($dtr_count > 0) {
                $grouped_data = [];
                $ipresent = 0;
                $employeeCount = [];
                // Standard daily hours per employee, resolved from their shift as
                // the DTR rows are read and frozen onto the payroll item below.
                $emp_day_hours = [];

                // Preload each employee's rest-day schedule periods overlapping this payroll
                // period, so we can auto-count rest-day duty from the DTR (replacing the old
                // hardcoded "Sunday" assumption). Keyed by employee_id.
                // The same rows also carry the shift's total_hours, which is what
                // one full day of work means for that employee — used below to turn
                // worked hours into days present and a daily rate into an hourly
                // one, in place of a hardcoded 8-hour day.
                // EVERY period, not just those overlapping the payroll dates. The
                // helpers above fall back to the nearest period when none covers a
                // date, and they cannot fall back to rows never fetched: an April
                // payroll for someone whose first assignment starts in May came
                // back with no schedule at all, which defaulted the working day to
                // 8h, priced night differential at 0 and lost rest-day duty.
                $restMap = [];
                $wsCache = [];   // work_schedules rows looked up by a row's stamped schedule_id
                $rq = $this->db->prepare(
                    "SELECT es.employee_id, es.effective_from, es.effective_to, es.rest_days,
                            ws.total_hours, ws.has_nsd, ws.nsd_rate
                     FROM employee_schedules es
                     LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
                     ORDER BY es.effective_from DESC"
                );
                $rq->execute();
                $rres = $rq->get_result();
                while ($rrow = $rres->fetch_assoc()) {
                    $restMap[$rrow['employee_id']][] = $rrow;
                }
                // Rotating staff answer per DAY, not per weekday — see loadDutyRestMap().
                $this->loadDutyRestMap($date_from, $date_to);

                // Preload approved PAID leave days per employee overlapping this period.
                // Paid leave (leave_types.is_paid = 1) is treated as paid-present: it does
                // NOT count as an absence (monthly) and IS paid (daily). Unpaid (LWOP) leave
                // needs no handling — with no DTR row it already falls into the absent tally.
                // Keyed [employee_id][Y-m-d] => day fraction (0.5 for the half-day date).
                // ── Paid leave, capped at the employee's EARNED credits ──────────
                // A `no_limit` type (e.g. Sick Leave) may be FILED without limit, but
                // only the days covered by that year's credits are PAID; anything past
                // the balance falls through as an unpaid absence. Credits are consumed
                // chronologically, so the earliest approved leave is paid first.
                //
                // This needs the WHOLE year's approved leave, not just the days inside
                // this payroll period — a day in August is only payable if the running
                // total since January still has credits left when it is reached.
                //
                // For capped types the filing guard already prevents approved days from
                // exceeding credits, so this is a no-op for them in normal operation and
                // only bites if credits were later reduced below what was already approved.
                $leaveMap = [];
                $pf = date('Y-m-d', strtotime($date_from));
                $pt = date('Y-m-d', strtotime($date_to));
                $yr_from = (int) date('Y', strtotime($date_from));
                $yr_to   = (int) date('Y', strtotime($date_to));

                $lvq = $this->db->prepare(
                    "SELECT lr.employee_id, lr.leave_type_id, YEAR(lr.date_from) AS lyear,
                            lr.dates, lr.date_from, lr.date_to, lr.is_half_day, lr.half_date
                     FROM leave_requests lr
                     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                     WHERE lr.status = 1 AND lt.is_paid = 1
                       AND YEAR(lr.date_from) BETWEEN ? AND ?
                     ORDER BY lr.date_from ASC, lr.id ASC"
                );
                $lvq->bind_param('ii', $yr_from, $yr_to);
                $lvq->execute();
                $lvres = $lvq->get_result();

                // Flatten every approved paid-leave day into one list per
                // employee + leave type + leave year.
                $lvDays = [];
                while ($lv = $lvres->fetch_assoc()) {
                    $eid  = $lv['employee_id'];
                    $key  = $eid . '|' . (int) $lv['leave_type_id'] . '|' . (int) $lv['lyear'];
                    $days = [];
                    if (!empty($lv['dates'])) {
                        $decoded = json_decode($lv['dates'], true);
                        if (is_array($decoded)) $days = $decoded;
                    }
                    if (!$days) {   // fall back to the inclusive from..to range
                        for ($d = strtotime($lv['date_from']); $d <= strtotime($lv['date_to']); $d = strtotime('+1 day', $d)) {
                            $days[] = date('Y-m-d', $d);
                        }
                    }
                    foreach ($days as $dy) {
                        $ymd  = date('Y-m-d', strtotime($dy));
                        $frac = ((int) $lv['is_half_day'] === 1 && !empty($lv['half_date'])
                                 && date('Y-m-d', strtotime($lv['half_date'])) === $ymd) ? 0.5 : 1.0;
                        $lvDays[$key][] = ['ymd' => $ymd, 'frac' => $frac, 'eid' => $eid];
                    }
                }

                // Credits available per employee + type + year (0 when never set).
                $lvCredits = [];
                $ccq = $this->db->query("SELECT employee_id, leave_type_id, year, credits
                                         FROM employee_leave_credits WHERE year BETWEEN $yr_from AND $yr_to");
                if ($ccq) while ($cc = $ccq->fetch_assoc()) {
                    $lvCredits[$cc['employee_id'] . '|' . (int) $cc['leave_type_id'] . '|' . (int) $cc['year']]
                        = (float) $cc['credits'];
                }

                // Walk the year chronologically, spending credits; only the covered
                // portion of each day is paid, and only days inside THIS payroll
                // period are written to the map this run.
                foreach ($lvDays as $key => $rows) {
                    usort($rows, function ($a, $b) { return strcmp($a['ymd'], $b['ymd']); });
                    $left = $lvCredits[$key] ?? 0.0;
                    foreach ($rows as $r) {
                        if ($left <= 0) break;                    // credits spent — rest is unpaid
                        $paidFrac = min($r['frac'], $left);       // may pay a half of a whole day
                        $left    -= $paidFrac;
                        if ($r['ymd'] < $pf || $r['ymd'] > $pt) continue;   // outside this cutoff
                        $eid = $r['eid'];
                        if (!isset($leaveMap[$eid][$r['ymd']]) || $leaveMap[$eid][$r['ymd']] < $paidFrac) {
                            $leaveMap[$eid][$r['ymd']] = $paidFrac;   // overlapping leaves → keep larger fraction
                        }
                    }
                }

                // Preload declared-holiday dates (legal + special) in this period,
                // keyed Y-m-d => calendar type (1 = legal, 3 = special).
                //
                // This calendar — NOT DTR_details.day_type — is the source of truth
                // for holiday pay. day_type is stamped when the DTR row is written,
                // so it goes stale when a holiday is declared afterwards and cannot
                // be re-stamped once the batch is final-approved (recompute_dtr
                // refuses status 2, which is exactly the status payroll reads). The
                // calendar is re-read on every calculation, so adding a missed
                // holiday and hitting Recalculate corrects the run retroactively.
                //
                // Two uses below: a holiday is a paid non-working day, so a MONTHLY
                // employee who didn't work it must NOT be counted absent for it; and
                // a day actually WORKED on a holiday earns its premium.
                $holidayDates = [];
                $hq = $this->db->prepare(
                    "SELECT type, start_date, end_date FROM calendar_events
                     WHERE type IN (1, 3) AND start_date <= ? AND COALESCE(end_date, start_date) >= ?"
                );
                $hq->bind_param('ss', $date_to, $date_from);
                $hq->execute();
                $hres = $hq->get_result();
                while ($h = $hres->fetch_assoc()) {
                    $hEnd = $h['end_date'] ?: $h['start_date'];
                    for ($d = strtotime($h['start_date']); $d <= strtotime($hEnd); $d = strtotime('+1 day', $d)) {
                        $hymd = date('Y-m-d', $d);
                        // A date carrying both kinds is paid as the legal holiday.
                        if (!isset($holidayDates[$hymd]) || (int) $h['type'] === 1) {
                            $holidayDates[$hymd] = (int) $h['type'];
                        }
                    }
                }

                // Approved OT and rest-day requests in the period — the ONLY
                // overtime payroll pays, and only UP TO the hours approved.
                // A rest-day filing names the whole day (7.5 on a 7.00 + 0.68
                // row) and the min() below keeps that from inflating anything:
                // it authorizes the 0.68 the scans show, nothing more. The DTR row
                // carries the raw computed excess (and a Recompute restores it
                // even after an approval overwrote it), so the row figure can
                // exceed what was authorized — an employee who filed 5 hrs with
                // 5.35 on the scans is paid 5. The scans still cap it from the
                // other side: approved 5 with only 4.2 rendered pays 4.2.
                $otApproved = [];   // eid => 'Y-m-d' => approved hours (summed)
                $oq = $this->db->prepare(
                    "SELECT employee_id, request_date, COALESCE(ot_hours_requested, 0) AS hrs
                     FROM attendance_requests
                     WHERE request_type IN ('overtime', 'rest_day') AND status = 1
                       AND request_date BETWEEN ? AND ?"
                );
                $oq->bind_param('ss', $date_from, $date_to);
                $oq->execute();
                $ores = $oq->get_result();
                while ($o = $ores->fetch_assoc()) {
                    $oid = (int) $o['employee_id'];
                    $oymd = date('Y-m-d', strtotime($o['request_date']));
                    $otApproved[$oid][$oymd] = ($otApproved[$oid][$oymd] ?? 0) + (float) $o['hrs'];
                }

                foreach ($result as $row) {
                    $employee_id = $row["employee_id"];
                    $isAutoDeduct = $row["isAutoDeduct"];
                    $sss_fund = $row["sss_fund"];
                    $allowance_rate = $row["allowance_rate"];
                    $site_id = $row['site_id'];
                    // Check if the employee_id already exists in the count array
                    if (isset($employeeCount[$employee_id])) {
                        // If it exists, increment the count
                        $employeeCount[$employee_id]++;
                    } else {
                        // If it doesn't exist, initialize the count to 1
                        $employeeCount[$employee_id] = 1;
                    }

                    $ymd = date('Y-m-d', strtotime($row['date_time']));
                    // One full day for THIS employee on THIS date. The value frozen
                    // on the DTR row when the punch was recorded wins; only rows
                    // predating that column resolve it from the roster now, and only
                    // an employee with no schedule at all falls back to 8 hours.
                    $day_hours = isset($row['day_hours']) && $row['day_hours'] !== null
                        ? day_hours_or_default($row['day_hours'])
                        : $this->dayHoursForDate($restMap[$employee_id] ?? [], $ymd);
                    $emp_day_hours[$employee_id] = $day_hours;

                    // Cap a single day's worth of hours at one full day
                    $work_hours = floor($row["work_hours"]) >= $day_hours ? $day_hours : $row["work_hours"];

                    // Fraction of the day actually WORKED — the basis for rest-day
                    // and holiday premiums, which follow hours rendered. (Basic-pay
                    // day credit is computed separately below.)
                    if ($work_hours == $day_hours) {
                        $frac_worked = 1;
                    } else if ($day_hours == 8 && $work_hours == 4.5625) {
                        // Long-standing special case for the 8-hour day; left exactly
                        // as it was so existing runs keep reproducing. It has no
                        // meaning on any other shift length, hence the guard.
                        $frac_worked = 0.5625;
                    } else {
                        $frac_worked = $work_hours / $day_hours;
                    }

                    // Initialize the employee bucket first so the accumulators
                    // below never touch undefined keys.
                    if (!array_key_exists($employee_id, $grouped_data)) {
                        $grouped_data[$employee_id] = [
                            "total_hours" => 0,
                            "salary" => 0,
                            "present" => 0,
                            "per_minute" => 0,
                            "overtime" => 0,
                            "late_in_minutes" => 0,
                            "undertime" => 0,
                            "under_time" => 0,
                            "rest_duty" => 0,
                            // Night differential, carried from the DTR. Hours are summed
                            // and the peso amount is accrued per day at that day's own
                            // hourly rate and shift nsd_rate, so a mid-period schedule or
                            // rate change is priced on the day it applied.
                            "nsd_hours" => 0,
                            "nsd_amount" => 0,
                            // Worked day-fraction per date ('Y-m-d' => float). Mirrors what
                            // goes into "present", so approved paid leave on a day that was
                            // partly worked can be capped below (worked + leave <= 1 day).
                            "worked_frac" => [],
                            // Holiday duty actually rendered, counted from the holiday
                            // calendar as the DTR days are read (see $holidayDates).
                            // Only worked dates land here, so "worked = paid, not
                            // worked = not paid" needs no separate rule.
                            "legal_duty" => 0,
                            "special_duty" => 0,
                        ];
                        $ipresent++;
                    }

                    // Rest-day duty: if this DTR day is one of the employee's rest days
                    // (effective on that date), the worked fraction counts toward the
                    // rest-day premium instead of being assumed to be Sunday.
                    // Stamped flag wins for the same reason as day_hours above:
                    // changing the roster must not turn a closed day into (or out
                    // of) rest-day duty after the fact.
                    $was_rest = isset($row['schedule_id']) && $row['schedule_id'] !== null
                        ? ((int) ($row['is_rest_day'] ?? 0) === 1)
                        : $this->isRestDay($restMap[$employee_id] ?? [], $ymd);
                    if ($was_rest) {
                        $grouped_data[$employee_id]["rest_duty"] += $frac_worked;
                    }

                    // Holiday duty: this DTR date falls on a declared holiday, so the
                    // fraction worked earns the premium. Same shape as rest-day duty
                    // above — fractional here, rounded to whole days at insert.
                    $hol_type = (int) ($holidayDates[$ymd] ?? 0);
                    if ($hol_type === 1) {
                        $grouped_data[$employee_id]["legal_duty"] += $frac_worked;
                    } elseif ($hol_type === 3) {
                        $grouped_data[$employee_id]["special_duty"] += $frac_worked;
                    }

                    // ── Undertime: leaving BEFORE the shift ends ────────────────
                    // dtr_compute_day() already measures this per day, in hours, as
                    // max(0, sched_end − time_out) — strictly early departure, so it
                    // never overlaps the late minutes charged separately below and
                    // the two cannot double-charge the same shortfall.
                    //
                    // It was hardcoded to 0 here, so the column existed, the payslip
                    // printed it, and nothing was ever deducted.
                    //
                    // ×60 because payroll_items.under_time is MINUTES: payroll_earnings()
                    // prices it at per_minute (= per_day ÷ (day_hours × 60)).
                    //
                    // Not charged on a rest day — no shift was scheduled to leave early
                    // from — and capped by any approved paid leave covering the day, so
                    // a half-day leave is not also billed as half a day of undertime.
                    $ut_hours = (float) ($row['undertime'] ?? 0);
                    if ($was_rest) {
                        $ut_hours = 0.0;
                    } elseif (!empty($leaveMap[$employee_id][$ymd])) {
                        $leave_hours = min(1.0, (float) $leaveMap[$employee_id][$ymd]) * $day_hours;
                        $ut_hours = max(0.0, $ut_hours - $leave_hours);
                    }
                    $grouped_data[$employee_id]["under_time"] += $ut_hours * 60;

                    // ── Day credit for basic pay ────────────────────────────────
                    // A scheduled day the employee showed up for stands as ONE whole
                    // day; the late/undertime minutes carved out of it are deducted
                    // in pesos (payroll_earnings). Crediting only the worked fraction
                    // here — as this used to — charged the same minutes twice on the
                    // daily basis (paid 5.35 of 8 hrs AND deducted 2:39 UT), and on
                    // the monthly basis let accumulated early-outs round into a
                    // phantom "absent" day on top of the UT deduction.
                    //
                    // worked + late + charged-UT spans the whole shift on a complete
                    // row, so the credit is exactly 1; an incomplete row (no time-out)
                    // has all three at 0 and credits nothing; approved paid leave
                    // reduces the charged UT above, and the paid-leave cap fills the
                    // remainder of the day, so leave days still total 1. Rest-day
                    // duty keeps the worked fraction — no shift was owed, and UT is
                    // never charged there.
                    $late_hours = (float) ($row['late'] ?? 0);
                    $days = $was_rest
                        ? $frac_worked
                        : min(1, $frac_worked + ($late_hours + $ut_hours) / $day_hours);

                    $per_day = $row['salary'];
                    $basic_pay = $row['basic_pay'];
                    $per_hour = $per_day / $day_hours;
                    $minutesPerDay = 24 * 60;
                    $per_minute =  round($per_day / $minutesPerDay, 2);
                    $salary = $work_hours * $per_hour;

                    // Add the work hours and pay to the total for the current employee
                    $grouped_data[$employee_id]["total_hours"] += $work_hours;
                    $grouped_data[$employee_id]["salary"] = $salary;
                    $grouped_data[$employee_id]["basic_pay"] = $row['basic_pay'];
                    $grouped_data[$employee_id]["ot_rate"] = $row['ot_rate'];
                    $grouped_data[$employee_id]["sss_fund"] = $row["sss_fund"];
                    $grouped_data[$employee_id]["per_minute"] = $per_minute;
                    $grouped_data[$employee_id]["per_day"] = $per_day;
                    $grouped_data[$employee_id]["present"] += $days;
                    // Same fraction, keyed by date — the paid-leave cap reads this.
                    $grouped_data[$employee_id]["worked_frac"][$ymd] =
                        ($grouped_data[$employee_id]["worked_frac"][$ymd] ?? 0) + $days;
                    // Night differential (Labor Code: a premium on each hour worked
                    // between 10PM and 6AM). The DTR row already holds the hours;
                    // they used to stop there — payroll_items had no ND column, so
                    // view_payslip's $payroll['nsd_hours'] read a key that never
                    // existed and every payslip showed ND as 0.00.
                    // Priced here, per day, at this shift's own rate.
                    $nsd_day = (float) ($row['nsd_hours'] ?? 0);
                    if ($nsd_day > 0) {
                        // Priced against the shift stamped on the row, so the
                        // premium matches the shift the night was worked under.
                        $sched_nsd = null;
                        if (isset($row['schedule_id']) && $row['schedule_id'] !== null) {
                            $sid = (int) $row['schedule_id'];
                            if (!isset($wsCache[$sid])) {
                                $wsq = $this->db->query("SELECT has_nsd, nsd_rate FROM work_schedules WHERE id = $sid LIMIT 1");
                                $wsCache[$sid] = $wsq ? $wsq->fetch_assoc() : null;
                            }
                            $sched_nsd = $wsCache[$sid];
                        }
                        if (!$sched_nsd) $sched_nsd = $this->scheduleOnDate($restMap[$employee_id] ?? [], $ymd);
                        // has_nsd off => the shift does not earn the premium at all.
                        $nsd_rate  = (empty($sched_nsd) || (int) ($sched_nsd['has_nsd'] ?? 0) !== 1)
                            ? 0.0
                            : (float) ($sched_nsd['nsd_rate'] ?? 0);
                        $grouped_data[$employee_id]["nsd_hours"]  += $nsd_day;
                        $grouped_data[$employee_id]["nsd_amount"] += $nsd_day * $per_hour * $nsd_rate;
                    }

                    // Only OT that was filed and APPROVED is paid (see $otApproved
                    // above), capped at the approved hours. Raw excess on an
                    // uncovered date stays on the DTR sheet as information but
                    // earns nothing.
                    if (!empty($otApproved[(int) $employee_id][$ymd])) {
                        $grouped_data[$employee_id]["overtime"] +=
                            min((float) $row['overtime'], $otApproved[(int) $employee_id][$ymd]);
                    }
                    // ×60: dtr_compute_day() returns late in HOURS ((time_in − sched_start)
                    // ÷ 3600) but payroll_items.late is MINUTES — payroll_earnings() prices
                    // it at per_minute, and the sheet labels the column "Min". Copied
                    // straight across, an employee 9.75 hours late was charged 9.75
                    // MINUTES: ₱20.31 instead of ₱1,218.75 on a ₱1,000 / 8-hour day.
                    // The accumulator has always been named late_in_minutes; only the
                    // value feeding it was wrong.
                    $grouped_data[$employee_id]["late_in_minutes"]  += $row['late'] * 60;
                    $grouped_data[$employee_id]["undertime"]  +=  $row['undertime'];
                    $grouped_data[$employee_id]["isAutoDeduct"]  =  $isAutoDeduct;
                    $grouped_data[$employee_id]["site_id"]  = $site_id;
                    $grouped_data[$employee_id]["sss_fund"]  = $sss_fund;
                    $grouped_data[$employee_id]["allowance_amount"]  = $allowance_rate;
                    $grouped_data[$employee_id]["rate_type"]  = in_array($row['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $row['rate_type'] : 'daily';
                    $grouped_data[$employee_id]["date_time"]  = $row['date_time'];
                }
                // ── Leave-only employees (approved paid leave, zero attendance) ──
                // $grouped_data is built solely from approved DTR rows, so an
                // employee whose whole period is covered by approved paid leave
                // used to vanish from the batch entirely — their leave was never
                // paid. Seed a zero-attendance row for them here; the paid-leave
                // pricing below fills in the payable days. Scoped to this batch's
                // sites via the employee's most recent DTR site (an employee with
                // no DTR history at all is included on the first configured site).
                $leaveOnly = array_diff(array_keys($leaveMap), array_keys($grouped_data));
                if ($leaveOnly) {
                    $loIds = implode(',', array_map('intval', $leaveOnly));
                    $siteIntList = array_map('intval', $site_ids);
                    $loq = $this->db->query(
                        "SELECT employee.id, employee.salary, employee.allowance_rate, employee.sss_fund,
                                employee.basic_pay, employee.rate_type, employee.ot_rate, employee.isAutoDeduct
                         FROM employee
                         WHERE employee.id IN ($loIds) AND employee.status = 1 $exclude_clause"
                    );
                    if ($loq) while ($lo = $loq->fetch_assoc()) {
                        $lo_id = (int) $lo['id'];
                        $lsq = $this->db->query(
                            "SELECT DTR.site_id FROM DTR_details
                             INNER JOIN DTR ON DTR.id = DTR_details.ddtr_id
                             WHERE DTR_details.employee_id = $lo_id
                             ORDER BY DTR_details.date_time DESC LIMIT 1"
                        );
                        $lastSite = ($lsq && $lsq->num_rows) ? (int) $lsq->fetch_assoc()['site_id'] : null;
                        if ($lastSite !== null && !in_array($lastSite, $siteIntList, true)) continue;
                        $lo_per_day = (float) $lo['salary'];
                        $grouped_data[$lo_id] = [
                            "total_hours" => 0,
                            "salary" => 0,
                            "present" => 0,
                            "per_minute" => round($lo_per_day / (24 * 60), 2),
                            "overtime" => 0,
                            "late_in_minutes" => 0,
                            "undertime" => 0,
                            "under_time" => 0,
                            "rest_duty" => 0,
                            "nsd_hours" => 0,
                            "nsd_amount" => 0,
                            "worked_frac" => [],
                            "basic_pay" => $lo['basic_pay'],
                            "ot_rate" => $lo['ot_rate'],
                            "sss_fund" => $lo['sss_fund'],
                            "per_day" => $lo_per_day,
                            "isAutoDeduct" => $lo['isAutoDeduct'],
                            "site_id" => $lastSite ?? (int) ($siteIntList[0] ?? 0),
                            "allowance_amount" => $lo['allowance_rate'],
                            "rate_type" => in_array($lo['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $lo['rate_type'] : 'daily',
                            // Period start stands in for "last attendance" — the
                            // cross-cluster comparison below needs a valid date.
                            "date_time" => $date_from,
                        ];
                        $ipresent++;
                    }
                }

                foreach ($grouped_data as $employee_id => $data) {
                    $last_attendance = $data['date_time'];
                    $sql2 = "SELECT DTR_details.*, DTR.site_id
                            FROM DTR_details 
                            INNER JOIN DTR ON DTR.id = DTR_details.ddtr_id  
                            INNER JOIN employee ON  DTR_details.employee_id = employee.id  
                            WHERE date(DTR_details.date_time) BETWEEN ? AND ?  AND DTR.status = 2  AND DTR_details.status = 1   AND DTR.site_id NOT IN ($commaSeparatedSites)
                            AND employee_id = $employee_id ORDER BY date_time DESC
                            ";
                    $stmt2 = $this->db->prepare($sql2);
                    $stmt2->bind_param("ss", $date_from, $date_to);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    $data__details = [];
                    if ($result2->num_rows > 0) {
                        foreach ($result2 as $row2) {
                            // Same one-full-day cap as the main loop, on the shift the
                            // employee was working that date.
                            // Prefer the values frozen on the row, exactly as the main
                            // loop does — otherwise the same day could count as rest
                            // duty in one pass and not the other.
                            $r2ymd = date('Y-m-d', strtotime($row2["date_time"]));
                            $dh2 = ($row2['day_hours'] ?? null) !== null
                                ? day_hours_or_default($row2['day_hours'])
                                : $this->dayHoursForDate($restMap[$employee_id] ?? [], $r2ymd);
                            $rest2 = ($row2['schedule_id'] ?? null) !== null
                                ? ((int) ($row2['is_rest_day'] ?? 0) === 1)
                                : $this->isRestDay($restMap[$employee_id] ?? [], $r2ymd);
                            $work_hours2 = floor($row2["work_hours"]) >= $dh2 ? $dh2 : $row2["work_hours"];
                            $data__details[] = [
                                "site_id" => $row2["site_id"],
                                "date_time" => $row2["date_time"],
                                "work_hours" => $work_hours2,
                                "day_hours" => $dh2,
                                "is_rest_day" => $rest2,
                                "overtime" => $row2["overtime"],
                                "undertime" => $row2["undertime"],
                                "present" => $row2["present"],
                                "late" => $row2["late"],
                            ];
                        }
                        // compare attendance last and other cluster
                        $date1 = strtotime($last_attendance);
                        $date2 = strtotime($data__details[0]["date_time"]);
                        if ($date2 < $date1) {
                            $date1 = strtotime($last_attendance);
                            $date2 = strtotime($data__details[0]["date_time"]);
                            foreach ($data__details as $data__detail) {
                                $data['total_hours'] += $data__detail['work_hours'];
                                // Same approved-OT-only policy (and cap) as the main loop.
                                $d2ymd_ot = date('Y-m-d', strtotime($data__detail['date_time']));
                                if (!empty($otApproved[(int) $employee_id][$d2ymd_ot])) {
                                    $data['overtime'] +=
                                        min((float) $data__detail['overtime'], $otApproved[(int) $employee_id][$d2ymd_ot]);
                                }
                                // Same hours→minutes conversion as the main loop above:
                                // the DTR measures both in hours, payroll_items stores
                                // minutes. Cross-cluster days were losing the same 60×.
                                $data['undertime'] += $data__detail['undertime'];
                                $data['under_time'] = ($data['under_time'] ?? 0)
                                    + (empty($data__detail['is_rest_day']) ? (float) $data__detail['undertime'] * 60 : 0);
                                $data['late_in_minutes'] += $data__detail['late'] * 60;
                                // Same whole-day credit as the main loop: late/UT are
                                // deducted as minutes, so the day they were carved from
                                // must be credited whole or they'd be charged twice.
                                $frac2 = $data__detail['work_hours'] / $data__detail['day_hours'];
                                $credit2 = !empty($data__detail['is_rest_day'])
                                    ? $frac2
                                    : min(1, $frac2 + ((float) $data__detail['late'] + (float) $data__detail['undertime']) / $data__detail['day_hours']);
                                $data['present'] += $credit2;
                                // Count rest-day duty from cross-cluster attendance too.
                                $d2ymd = date('Y-m-d', strtotime($data__detail['date_time']));
                                // Keep the per-date worked fraction in step with "present"
                                // so the paid-leave cap sees cross-cluster days too.
                                $data['worked_frac'][$d2ymd] = ($data['worked_frac'][$d2ymd] ?? 0) + $credit2;
                                if (!empty($data__detail['is_rest_day'])) {
                                    $data['rest_duty'] = ($data['rest_duty'] ?? 0) + $data__detail['work_hours'] / $data__detail['day_hours'];
                                }
                            }
                        } else {
                            continue;
                        }
                    }



                    $contribute_amount = 0;
                    // get deductions 
                    $deduction_amount =  0;
                    $deductions = [];
                    $contributions = [];
                    $loans = [];
                    $loans = [];
                    $refunds = [];
                    foreach ($settings as $setting) {
                        if ($setting['type'] == 1) {
                            $contibution_id = $setting['id'];
                            $query = "SELECT * FROM employee_contributions WHERE employee_id = ? AND contribution_id = ? ";
                            $stmt = $this->db->prepare($query);
                            $stmt->bind_param("is", $employee_id,  $contibution_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                // ── Auto-computed statutory contributions ──────────────
                                // The SSS / PhilHealth calculators existed but their call
                                // site was commented out, so every contribution was the
                                // flat amount typed on employee_contributions — which goes
                                // stale the moment a salary or a statutory rate changes,
                                // silently and invisibly.
                                //
                                // Now: when the employee is flagged isAutoDeduct AND the
                                // contribution names a schedule we know (SSS/PHIC/HDMF)
                                // AND a rate is in force on this run's date, the share is
                                // computed from the effectivity-dated table. Anything else
                                // falls back to the stored amount, so an installation that
                                // has not run the migration behaves exactly as before.
                                $amount = (float) $row['amount'];
                                $auto   = false;
                                if (!empty($data['isAutoDeduct'])) {
                                    $cname = $contribution_names[(int) $row['contribution_id']] ?? '';
                                    $kind  = $cname !== '' ? contribution_kind($cname) : null;
                                    if ($kind !== null) {
                                        // Assessed on monthly compensation: basic_pay for
                                        // monthly/fixed staff, else the daily rate annualised
                                        // over the standard 26-day month.
                                        $rt_c    = $data['rate_type'] ?? 'daily';
                                        $monthly = ($rt_c === 'monthly' || $rt_c === 'fixed')
                                            ? (float) $data['basic_pay']
                                            : (float) $data['per_day'] * 26;
                                        $calc = statutory_employee_share($this->db, $kind, $monthly, $date_from, $date_to);
                                        if ($calc !== null) { $amount = $calc; $auto = true; }
                                    }
                                }
                                $contribute_amount += $amount;
                                $contributions[] = [
                                    "amount" => $amount,
                                    "contribution_id" => (int) $row['contribution_id'],
                                    // Recorded so the payslip and the remittance report can
                                    // show whether a figure was computed or hand-entered.
                                    "auto" => $auto ? 1 : 0,
                                ];
                            }
                        }
                        if ($setting['type'] == 2) {
                            $deduction_id = $setting['id'];
                            $query = "SELECT * FROM employee_deductions WHERE employee_id = ?  AND deduction_id = ? ";
                            $stmt = $this->db->prepare($query);
                            $stmt->bind_param("is", $employee_id, $deduction_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                // "First deduction date" gate — skip if it hasn't started this period.
                                if (!empty($row['effective_date']) && date('Y-m-d', strtotime($row['effective_date'])) > $date_to) {
                                    continue;
                                }
                                $total = (float) $row['total_amount'];
                                if ($total > 0) {
                                    // Amortizing deduction (behaves like a loan): cap at the
                                    // remaining balance and skip once fully paid. The balance is
                                    // decremented + recorded in deduction_history at Lock.
                                    if ((int) $row['status'] === 1) continue;
                                    $balance = (float) $row['balance'];
                                    if ($balance <= 0) continue;
                                    $ded = (float) $row['amount'];
                                    if ($balance < $ded) $ded = $balance;
                                    $deduction_amount += $ded;
                                    $deductions[] = ["amount" => $ded, "deduction_id" => (int) $row['deduction_id'], "type" => 1, "ded_row_id" => (int) $row['id'], "amortizing" => 1];
                                } else {
                                    // Flat recurring deduction — unchanged behavior.
                                    $deduction_amount += $row['amount'];
                                    $deductions[] = ["amount" => $row['amount'], "deduction_id" => (int) $row['deduction_id'], "type" => 1];
                                }
                            }
                        }

                        if ($setting['type'] == 3) {
                            $clt_id = (int)  $setting['id'];
                            $query = "SELECT * FROM loans WHERE employee_id = ?  AND loan_type = ? ";
                            $stmt = $this->db->prepare($query);
                            $stmt->bind_param("is", $employee_id, $clt_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                // Skip loans not yet started or fully paid. Deductions begin on
                                // effective_date when set, else on loan_date (the grant date).
                                $loan_start = !empty($row['effective_date']) ? $row['effective_date'] : $row['loan_date'];
                                if (!empty($loan_start) && date('Y-m-d', strtotime($loan_start)) > $date_to) {
                                    continue;
                                }
                                if ((int) $row['loan_status'] === 1) continue;
                                $balance = (float) $row['loan_balance'];
                                if ($balance <= 0) continue;
                                $damount = (float)  $row['damount'];
                                if ($balance < $damount) {
                                    $damount = $balance;
                                }
                                $loans[] = [
                                    "amount" => $damount,
                                    "deduction_id" => $row['loan_type'],
                                    "type" => 2
                                ];
                            }
                        }

                        if ($setting['type'] == 4) {
                            $refunds[] = ["amount" => 0, "refund_id" => (int)  $setting['id']];
                        }
                    }
                    // ── Resolve this employee's configured allowances for THIS run ──
                    // Each row contributes per its type (Monthly / Semi-Monthly /
                    // Once) and its effectivity window; see
                    // employee_allowance_amount_for_run(). Frozen onto the item as
                    // JSON, with a denormalised total for the SQL aggregates.
                    $item_allowances = [];
                    $allowance_total = 0.0;
                    foreach (($employee_allowances[$employee_id] ?? []) as $ea) {
                        $amt = employee_allowance_amount_for_run($ea, $date_from, $date_to);
                        if ($amt <= 0) continue;
                        $item_allowances[] = [
                            'allowance_id' => (int) $ea['allowance_id'],
                            'label'        => $ea['label'] ?? 'Allowance',
                            'type'         => (int) $ea['type'],
                            'amount'       => round($amt, 2),
                            // Frozen so the withholding base stays auditable even
                            // if the allowance is reclassified later.
                            'taxable'      => (int) ($ea['is_taxable'] ?? 1),
                            'cap'          => $ea['de_minimis_monthly_cap'] !== null
                                ? (float) $ea['de_minimis_monthly_cap'] : null,
                        ];
                        $allowance_total += $amt;
                    }
                    $allowance_total = round($allowance_total, 2);
                    $allowances_json = json_encode($item_allowances);

                    $contributions = json_encode($contributions);
                    $deductions = json_encode($deductions);
                    $loans = json_encode($loans);
                    $refunds = json_encode($refunds);
                    $payroll_id = $id;
                    // inser loan table
                    $salary = $data['salary'];
                    $total_hours = $data['total_hours'];
                    $under_time = $data['under_time'];
                    $late = $data['late_in_minutes'];
                    $present = $data['present'];
                    $sss_fund = $data['sss_fund'];
                    $per_minute = number_format($data['per_minute'], 2);
                    $per_day = $data['per_day'];
                    $ot_rate = $data['ot_rate'];
                    $ssite_id = $data['site_id'];
                    $basic_pay = $data['basic_pay'];
                    $ot = $data['overtime'];
                    $allowance_amount = $data['allowance_amount'];
                    // Rest-day duty days worked, auto-counted from the DTR above. Stored in the
                    // sunday_duty column (int) — rounded to whole days, matching the prior
                    // manual whole-day entry. Admin can still adjust it afterward.
                    $rest_duty = (int) round($data['rest_duty'] ?? 0);
                    // Holiday duty days worked, auto-counted from the holiday calendar
                    // above. Same int columns and same rounding as rest-day duty; the
                    // admin can still override either afterwards.
                    $legal_duty   = (int) round($data['legal_duty'] ?? 0);
                    $special_duty = (int) round($data['special_duty'] ?? 0);
                    // Night differential accumulated per day above: total 10PM–6AM hours
                    // and their peso premium, each day priced at that day's own hourly
                    // rate x the shift's nsd_rate. Frozen here like everything else.
                    $nsd_hours_total  = round((float) ($data['nsd_hours'] ?? 0), 2);
                    $nsd_amount_total = round((float) ($data['nsd_amount'] ?? 0), 2);
                    // Pay basis for this employee, frozen onto the payroll item so a later
                    // rate-type change doesn't retro-alter this run.
                    $rate_type = in_array($data['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $data['rate_type'] : 'daily';
                    // Standard hours in one working day for this employee, frozen for the
                    // same reason: payslips, prints and net recalculation divide by THIS
                    // value, so re-opening an old payroll after a shift change can't move
                    // its hourly rate or its days-present figures.
                    $day_hours = day_hours_or_default($emp_day_hours[$employee_id] ?? null);
                    // Absences are only meaningful for MONTHLY-rate employees (their pay is
                    // salary − absences). Expected working days = period days that are NOT the
                    // employee's rest days; absent = expected − days present (floored, whole days).
                    // Daily-rate employees keep absent = 0 (they're paid per day present).
                    $absent = 0;
                    // Approved PAID-leave day fractions on this employee's non-rest (expected)
                    // days in the period — counted as paid-present (own payslip line, and NOT
                    // an absence). Computed for all rate types; used below.
                    $paid_leave = 0;
                    if (!empty($leaveMap[$employee_id])) {
                        for ($d = strtotime($date_from); $d <= strtotime($date_to); $d = strtotime('+1 day', $d)) {
                            $ymd = date('Y-m-d', $d);
                            if (isset($leaveMap[$employee_id][$ymd]) && !$this->isRestDay($restMap[$employee_id] ?? [], $ymd, $employee_id)) {
                                // A day that was PARTLY WORKED can only take leave for the
                                // remainder of itself. "present" is already a fraction
                                // (work_hours / day_hours), so without this cap a half-day
                                // worked plus a filed leave paid the same date more than
                                // once — e.g. worked 0.52 + full-day leave 1.0 = 1.52 days.
                                // Clamped here, never below zero, so it can only ever reduce.
                                $worked = (float) ($data['worked_frac'][$ymd] ?? 0);
                                $paid_leave += min((float) $leaveMap[$employee_id][$ymd], max(0, 1 - $worked));
                            }
                        }
                    }
                    if ($rate_type === 'monthly') {
                        $expected_days = 0;
                        for ($d = strtotime($date_from); $d <= strtotime($date_to); $d = strtotime('+1 day', $d)) {
                            $eymd = date('Y-m-d', $d);
                            // Expected work days exclude rest days AND declared holidays
                            // (a holiday is paid non-working — never an absence).
                            if (!$this->isRestDay($restMap[$employee_id] ?? [], $eymd, $employee_id) && empty($holidayDates[$eymd])) {
                                $expected_days++;
                            }
                        }
                        // Paid leave is not an absence.
                        $absent = max(0, (int) round($expected_days - $present - $paid_leave));
                    }

                    $sql2 = "INSERT INTO payroll_items
                    (payroll_id, employee_id, salary, allowance_amount, contribute_amount,
                     deduction_amount, deductions, contributions, total_hours,
                     per_day, under_time, late, present, ot_rate, per_minute, ot, site_id, loans,basic_pay,sss_fund,refunds,sunday_duty,absent,paid_leave,rate_type,day_hours,nsd_hours,nsd_amount,legal_holiday,special_holiday,allowances,allowance_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $stmt2 = $this->db->prepare($sql2);
                    if (!$stmt2) {
                        throw new Exception('Failed to prepare statement: ' . $this->db->error);
                    }

                    $stmt2->bind_param(
                        'ssssssssssssssssssssssssssssssss',
                        $payroll_id,
                        $employee_id,
                        $salary,
                        $allowance_amount,
                        $contribute_amount,
                        $deduction_amount,
                        $deductions,
                        $contributions,
                        $total_hours,
                        $per_day,
                        $under_time,
                        $late,
                        $present,
                        $ot_rate,
                        $per_minute,
                        $ot,
                        $ssite_id,
                        $loans,
                        $basic_pay,
                        $sss_fund,
                        $refunds,
                        $rest_duty,
                        $absent,
                        $paid_leave,
                        $rate_type,
                        $day_hours,
                        $nsd_hours_total,
                        $nsd_amount_total,
                        $legal_duty,
                        $special_duty,
                        $allowances_json,
                        $allowance_total
                    );

                    try {
                        if (!$stmt2->execute()) {
                            throw new Exception('Failed to execute statement: ' . $stmt2->error);
                        }
                    } catch (Exception $e) {
                        error_log($e->getMessage()); // Logs error for debugging
                        throw new Exception('Failed to insert data: ' . $e->getMessage());
                    } finally {
                        // $stmt2->close(); // Ensure the statement is closed to free resources
                    }

                    $query_update = "UPDATE payroll SET status = ? WHERE id = ?";
                    $stmt3 = $this->db->prepare($query_update);
                    if ($stmt3 === false) {
                        throw new Exception('Failed to prepare the statement: ' . $this->db->error);
                    }
                    $status = 1;
                    $id = $pay['id'];
                    $stmt3->bind_param("ii", $status, $id);
                    try {
                        $stmt3->execute();
                    } catch (Exception $e) {
                        throw new Exception('Failed to update data: ' . $e->getMessage());
                    }
                }
            }

            // Fixed-salary employees are paid regardless of attendance, so they
            // won't appear in the DTR-driven set above. Add them here (present=0,
            // absent=0 → full salary share), skipping anyone already inserted this
            // run or already paid in an overlapping-period payroll.
            $fixedCount = $this->insertFixedEmployees($id, $date_from, $date_to, $settings, $excluded_clasif, $site_ids);

            if ($dtr_count > 0 || $fixedCount > 0) {
                // Mark the payroll as calculated (status 1).
                $upd = $this->db->prepare("UPDATE payroll SET status = 1 WHERE id = ?");
                $upd->bind_param("i", $id);
                $upd->execute();
                // Put back the hand-typed columns snapshotted before the DELETE.
                // An employee who dropped out of this run simply has no row to
                // restore onto, which is the correct outcome.
                $this->restore_manual_payroll_fields($id, $manualKeep);
                // Recalculating DELETEs and re-INSERTs payroll_items, so every
                // named one-off item is now pointing at a row id that no longer
                // exists. Re-attach them by employee before committing.
                $this->relink_payroll_extras($id);
                // Withholding tax needs the finished rows — gross, allowances and
                // statutory contributions all have to exist before a taxable base
                // can be derived. Runs before the net resync below so an
                // auto-posted tax lands inside the net rather than after it.
                $this->compute_payroll_tax($id);
                // Last, once every figure the net depends on is in place: store
                // a net that matches the gross this run just produced.
                $this->resync_payroll_nets($id);
                $this->db->commit();
                return ['result' => true, 'message' => 'save'];
            }

            $this->db->rollback();
            return ['result' => false, 'message' => 'Calculation failed: no DTR records and no fixed-salary employees for this period.'];
        } catch (mysqli_sql_exception $e) {
            return ['result' => false, 'message' => $e->getMessage()];
        }
        return ['result' => false, 'message' => 'save'];
    }

    /**
     * Compute withholding tax for every item in a run.
     *
     * Runs AFTER the items are inserted, so it can see each row's final gross,
     * allowances and statutory contributions — the taxable base cannot be known
     * before those exist.
     *
     * Always writes `tax_computed` and `taxable_income` (what the schedule says).
     * Only writes `tax` when pay_settings.tax_auto_post is on AND the row is not
     * flagged tax_override — so enabling the engine cannot silently restate a
     * figure an admin deliberately typed, and deploying it changes nothing until
     * someone turns it on.
     *
     * Method 2 (cumulative/annualised, RR 11-2018) prices against the employee's
     * year to date; method 1 applies the per-cutoff table. Both come from the
     * effectivity-dated tax_brackets table.
     */
    private function compute_payroll_tax($payrollId)
    {
        $payrollId = (int) $payrollId;
        if (!tax_bracket_config($this->db, 'semi_monthly', date('Y-m-d'))) return;   // unmigrated

        $pay = $this->db->query("SELECT date_from, date_to FROM payroll WHERE id = $payrollId")->fetch_assoc();
        if (!$pay) return;
        $date_from = date('Y-m-d', strtotime($pay['date_from']));
        $date_to   = date('Y-m-d', strtotime($pay['date_to']));
        $year      = (int) date('Y', strtotime($date_from));

        $auto   = $this->th13_setting('tax_auto_post', 0) >= 1;
        $method = (int) $this->th13_setting('tax_method', 1);
        $factor = statutory_period_factor($date_from, $date_to);

        // Statutory contribution ids — only these are deductible from the base.
        $gov = [];
        $cq = $this->db->query("SELECT id, contribution FROM contributions");
        if ($cq) while ($c = $cq->fetch_assoc()) {
            if (contribution_kind($c['contribution']) !== null) $gov[(int) $c['id']] = true;
        }

        // Year-to-date per employee, EXCLUDING this run, for the cumulative method.
        $ytd = [];
        if ($method === 2) {
            $yq = $this->db->query(
                "SELECT pi.employee_id,
                        COALESCE(SUM(pi.taxable_income), 0) AS taxable,
                        COALESCE(SUM(pi.tax), 0)            AS withheld
                   FROM payroll_items pi
                   INNER JOIN payroll p ON p.id = pi.payroll_id
                  WHERE YEAR(p.date_from) = $year AND p.id <> $payrollId
                    AND p.date_from < '" . $this->db->real_escape_string($date_from) . "'
                  GROUP BY pi.employee_id"
            );
            if ($yq) while ($y = $yq->fetch_assoc()) {
                $ytd[(int) $y['employee_id']] = ['taxable' => (float) $y['taxable'], 'withheld' => (float) $y['withheld']];
            }
        }

        // 13th month posted onto this run. Non-taxable up to ₱90,000 for the year
        // [Sec 32(B)(7)(e) NIRC]; the excess is taxable compensation and must be
        // withheld on. The cap was previously applied only in the alphalist
        // REPORT, long after the money had gone out untaxed.
        $th13_excess = [];
        if (($h = $this->db->query("SHOW TABLES LIKE 'payroll_item_extras'")) && $h->num_rows) {
            $xq = $this->db->query(
                "SELECT x.payroll_item_id, SUM(x.amount) AS amt
                   FROM payroll_item_extras x
                  WHERE x.payroll_id = $payrollId AND x.kind = 2 AND x.label = '13th Month Pay'
                  GROUP BY x.payroll_item_id"
            );
            if ($xq) while ($x = $xq->fetch_assoc()) {
                $th13_excess[(int) $x['payroll_item_id']] = max(0.0, (float) $x['amt'] - BIR_13TH_CAP);
            }
        }

        $rows = $this->db->query("SELECT * FROM payroll_items WHERE payroll_id = $payrollId");
        if (!$rows) return;

        $upd = $this->db->prepare(
            "UPDATE payroll_items SET taxable_income = ?, tax_computed = ?, tax = ? WHERE id = ?"
        );
        $updNoPost = $this->db->prepare(
            "UPDATE payroll_items SET taxable_income = ?, tax_computed = ? WHERE id = ?"
        );
        if (!$upd || !$updNoPost) return;

        while ($row = $rows->fetch_assoc()) {
            $itemId  = (int) $row['id'];
            $eid     = (int) $row['employee_id'];
            $taxable = payroll_taxable_income($row, $gov, $factor)
                     + ($th13_excess[$itemId] ?? 0.0);

            $tax = $method === 2
                ? withholding_tax_cumulative(
                    $this->db, $taxable,
                    $ytd[$eid]['taxable'] ?? 0.0, $ytd[$eid]['withheld'] ?? 0.0,
                    $date_from, $date_to)
                : withholding_tax_per_cutoff($this->db, $taxable, $date_from, $date_to);
            if ($tax === null) continue;

            if ($auto && (int) ($row['tax_override'] ?? 0) === 0) {
                $upd->bind_param('dddi', $taxable, $tax, $tax, $itemId);
                $upd->execute();
            } else {
                $updNoPost->bind_param('ddi', $taxable, $tax, $itemId);
                $updNoPost->execute();
            }
        }
    }

    /**
     * READ-ONLY reconciliation for one payroll run — the non-destructive twin of
     * resync_payroll_nets() below.
     *
     * For every item it recomputes gross from its components and net from that
     * gross, then compares the result to the `net` actually stored on the row.
     * Nothing is written. A run that reconciles is proof the sheet, the payslip,
     * the exports and the stored figure are all quoting the same arithmetic;
     * a mismatch is the only early warning that they have drifted apart —
     * previously nothing checked this at all, and a stale net could survive
     * until an employee noticed their payslip disagreed with their bank credit.
     *
     * Returns:
     *   ok         → true when every row balances
     *   checked    → number of items examined
     *   mismatches → [employee_no, name, stored_net, expected_net, diff, ...]
     *   totals     → run-level gross / deductions / refunds / net
     */
    function payroll_reconcile($payrollId = null)
    {
        $payrollId = (int) ($payrollId ?? $_POST['id'] ?? 0);
        if ($payrollId <= 0) return ['result' => false, 'message' => 'Invalid payroll id.'];

        // Same one-off item folding as resync_payroll_nets(): kind 2 adds to
        // gross, kind 1 adds to deductions.
        $xAdd = $xLess = [];
        if (($h = $this->db->query("SHOW TABLES LIKE 'payroll_item_extras'")) && $h->num_rows) {
            $xq = $this->db->query(
                "SELECT payroll_item_id, kind, amount FROM payroll_item_extras
                  WHERE payroll_id = $payrollId"
            );
            if ($xq) while ($x = $xq->fetch_assoc()) {
                $k = (int) $x['payroll_item_id'];
                if ((int) $x['kind'] === 2) $xAdd[$k]  = ($xAdd[$k]  ?? 0) + (float) $x['amount'];
                else                        $xLess[$k] = ($xLess[$k] ?? 0) + (float) $x['amount'];
            }
        }

        $rows = $this->db->query(
            "SELECT pi.*, pay.type AS payroll_type,
                    e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS name
               FROM payroll_items pi
               INNER JOIN payroll pay ON pay.id = pi.payroll_id
               INNER JOIN employee e  ON e.id   = pi.employee_id
              WHERE pi.payroll_id = $payrollId
              ORDER BY e.lastname, e.firstname"
        );
        if (!$rows) return ['result' => false, 'message' => $this->db->error];

        $mismatches = [];
        $checked = 0;
        $T = ['gross' => 0.0, 'ded' => 0.0, 'ref' => 0.0, 'net' => 0.0, 'stored' => 0.0];

        while ($row = $rows->fetch_assoc()) {
            $checked++;
            $itemId = (int) $row['id'];

            $earn  = payroll_earnings($row);
            $gross = $earn['gross'] + ($xAdd[$itemId] ?? 0);

            $ded = 0.0;
            foreach (['contributions', 'deductions', 'loans'] as $col) {
                foreach ((json_decode($row[$col] ?? '', true) ?: []) as $c) {
                    $ded += (float) ($c['amount'] ?? 0);
                }
            }
            $ded += (float) $row['sss_fund'] + (float) $row['jei_advances']
                  + (float) $row['jcc_advances'] + (float) $row['tax']
                  + (float) $row['other_deduction'] + ($xLess[$itemId] ?? 0);

            $ref = 0.0;
            foreach ((json_decode($row['refunds'] ?? '', true) ?: []) as $r) {
                $ref += (float) ($r['amount'] ?? 0);
            }

            $expected = $gross - $ded + $ref + (float) ($row['adjustment'] ?? 0);
            $stored   = (float) $row['net'];

            $T['gross']  += $gross;
            $T['ded']    += $ded;
            $T['ref']    += $ref;
            $T['net']    += $expected;
            $T['stored'] += $stored;

            // The allowances JSON is the breakdown; allowance_total is its
            // denormalised mirror, read by the SQL aggregates. They are written
            // together, so a difference means one of them was edited behind the
            // other's back — report it as its own finding rather than letting the
            // sheet and the dashboard quietly disagree.
            $json_sum = 0.0;
            foreach (payroll_allowance_list($row) as $al) $json_sum += (float) ($al['amount'] ?? 0);
            if (abs($json_sum - (float) ($row['allowance_total'] ?? 0)) > 0.01) {
                $mismatches[] = [
                    'item_id'     => $itemId,
                    'employee_no' => $row['employee_no'],
                    'name'        => $row['name'],
                    'gross'       => round($gross, 2),
                    'deductions'  => round($ded, 2),
                    'stored_net'  => round((float) $row['allowance_total'], 2),
                    'expected_net' => round($json_sum, 2),
                    'diff'        => round($json_sum - (float) ($row['allowance_total'] ?? 0), 2),
                    'note'        => 'allowance_total does not match the allowances breakdown',
                ];
            }

            // A centavo of float noise is not a drift; anything above it is.
            if (abs($expected - $stored) > 0.01) {
                $mismatches[] = [
                    'item_id'     => $itemId,
                    'employee_no' => $row['employee_no'],
                    'name'        => $row['name'],
                    'gross'       => round($gross, 2),
                    'deductions'  => round($ded, 2),
                    'stored_net'  => round($stored, 2),
                    'expected_net' => round($expected, 2),
                    'diff'        => round($expected - $stored, 2),
                ];
            }
        }

        return [
            'result'     => true,
            'ok'         => count($mismatches) === 0,
            'checked'    => $checked,
            'mismatches' => $mismatches,
            'totals'     => array_map(function ($v) { return round($v, 2); }, $T),
        ];
    }

    /**
     * Recompute and store `net` for every item in a payroll, in one pass.
     *
     * The INSERTs above never set `net` (the column defaults to 0) and nothing
     * else refreshed it, so after a calculation the stored net was either zero
     * or — worse — a leftover from the PREVIOUS run, until someone happened to
     * open the payroll screen and let it save. Everything that reads the column
     * straight (the employee portal, the payslip, exports) was therefore quoting
     * a figure from a different moment than the gross beside it.
     *
     * Uses the shared payroll_earnings() formula, so the stored net is by
     * construction the same number the sheet renders.
     */
    private function resync_payroll_nets($payrollId)
    {
        $payrollId = (int) $payrollId;

        // One-off items per row, folded in the same way resync_item_net() does:
        // kind 2 adds to gross, kind 1 adds to deductions.
        $xAdd = $xLess = [];
        if (($h = $this->db->query("SHOW TABLES LIKE 'payroll_item_extras'")) && $h->num_rows) {
            $xq = $this->db->query(
                "SELECT payroll_item_id, kind, amount FROM payroll_item_extras
                  WHERE payroll_id = $payrollId"
            );
            if ($xq) while ($x = $xq->fetch_assoc()) {
                $k = (int) $x['payroll_item_id'];
                if ((int) $x['kind'] === 2) $xAdd[$k]  = ($xAdd[$k]  ?? 0) + (float) $x['amount'];
                else                        $xLess[$k] = ($xLess[$k] ?? 0) + (float) $x['amount'];
            }
        }

        $rows = $this->db->query(
            "SELECT pi.*, pay.type AS payroll_type
               FROM payroll_items pi
               INNER JOIN payroll pay ON pay.id = pi.payroll_id
              WHERE pi.payroll_id = $payrollId"
        );
        if (!$rows) return;

        $upd = $this->db->prepare("UPDATE payroll_items SET net = ? WHERE id = ?");
        if (!$upd) return;

        while ($row = $rows->fetch_assoc()) {
            $itemId = (int) $row['id'];
            $gross  = payroll_earnings($row)['gross'] + ($xAdd[$itemId] ?? 0);

            $ded = 0.0;
            foreach (['contributions', 'deductions', 'loans'] as $col) {
                foreach ((json_decode($row[$col] ?? '', true) ?: []) as $c) $ded += (float) ($c['amount'] ?? 0);
            }
            $ded += (float) $row['sss_fund'] + (float) $row['jei_advances']
                  + (float) $row['jcc_advances'] + (float) $row['tax'] + (float) $row['other_deduction']
                  + ($xLess[$itemId] ?? 0);

            $ref = 0.0;
            foreach ((json_decode($row['refunds'] ?? '', true) ?: []) as $r) $ref += (float) ($r['amount'] ?? 0);

            $net = $gross - $ded + $ref + (float) ($row['adjustment'] ?? 0);
            $upd->bind_param('di', $net, $itemId);
            $upd->execute();
        }
    }

    /**
     * Write back the columns a recalculation cannot regenerate, captured by
     * employee_id before the rebuild DELETEd their rows. Each restored row has
     * its stored net resynced, since adjustments and hand-entered deductions
     * are part of it. Silently does nothing when nothing was captured.
     */
    private function restore_manual_payroll_fields($payrollId, array $keep)
    {
        if (!$keep) return;
        $payrollId = (int) $payrollId;

        $upd = $this->db->prepare(
            "UPDATE payroll_items
                SET allowance_days = ?, jei_advances = ?, jcc_advances = ?, tax = ?,
                    tax_override = ?,
                    other_deduction = ?, adjustment = ?, adjustment_remarks = ?
              WHERE payroll_id = ? AND employee_id = ?"
        );
        if (!$upd) return;

        foreach ($keep as $employeeId => $m) {
            $ad  = (int) $m['allowance_days'];
            $jei = (float) $m['jei_advances'];
            $jcc = (float) $m['jcc_advances'];
            $tax = (float) $m['tax'];
            // Carried across the rebuild with the value it protects — restoring
            // a hand-typed tax while dropping its flag would let the next
            // auto-post pass overwrite the very figure just restored.
            $tov = (int) ($m['tax_override'] ?? 0);
            $od  = (float) $m['other_deduction'];
            $adj = (float) $m['adjustment'];
            $rem = (string) $m['adjustment_remarks'];
            $eid = (int) $employeeId;
            // i d d d i d d s i i  — ad, jei, jcc, tax, tax_override, od, adj, rem, payroll, employee
            $upd->bind_param('idddiddsii', $ad, $jei, $jcc, $tax, $tov, $od, $adj, $rem, $payrollId, $eid);
            $upd->execute();

            if ($upd->affected_rows > 0) {
                $find = $this->db->query(
                    "SELECT id FROM payroll_items
                      WHERE payroll_id = $payrollId AND employee_id = $eid LIMIT 1"
                );
                if ($find && ($r = $find->fetch_assoc())) {
                    $this->resync_item_net((int) $r['id']);
                }
            }
        }
    }

    /**
     * Insert payroll items for FIXED-rate (salaried) employees who need no
     * attendance. They are paid the full salary share for the period
     * (present=0, absent=0 → the monthly/fixed formula pays basic_pay in full),
     * minus their standard contributions/deductions/loans.
     *
     * Site-independent (fixed staff aren't tied to a site), so to avoid paying
     * one twice across site-scoped runs, an employee already holding an item in
     * ANY overlapping-period payroll is skipped. Returns the number inserted.
     */
    private function insertFixedEmployees($payroll_id, $date_from, $date_to, $settings, $excluded_clasif, $site_ids)
    {
        $payroll_id = (int) $payroll_id;
        $first_site = (int) (is_array($site_ids) && !empty($site_ids) ? $site_ids[0] : 0);

        $emps = $this->db->query("
            SELECT id, salary, allowance_rate, sss_fund, basic_pay, ot_rate
            FROM employee
            WHERE status = 1 AND rate_type = 'fixed'
              AND clasification_id NOT IN (SELECT id FROM clasification WHERE UPPER(clasification) IN ($excluded_clasif))
        ");
        if (!$emps) return 0;

        $inserted = 0;
        while ($emp = $emps->fetch_assoc()) {
            $employee_id = (int) $emp['id'];

            // Already inserted this run (e.g. a fixed employee who also had DTR)?
            $chk = $this->db->query("SELECT 1 FROM payroll_items WHERE payroll_id = $payroll_id AND employee_id = $employee_id LIMIT 1");
            if ($chk && $chk->num_rows) continue;

            // Already paid in another payroll whose period overlaps this one? Skip
            // to prevent double-paying a site-independent salaried employee.
            $dup = $this->db->prepare("
                SELECT 1 FROM payroll_items pi
                INNER JOIN payroll p ON p.id = pi.payroll_id
                WHERE pi.employee_id = ? AND p.id <> ? AND p.date_from <= ? AND p.date_to >= ? LIMIT 1
            ");
            $dup->bind_param('iiss', $employee_id, $payroll_id, $date_to, $date_from);
            $dup->execute();
            if ($dup->get_result()->num_rows) continue;

            // Standard contributions / deductions / loans / refunds (same rules as
            // the DTR path — a salaried employee still carries these).
            $contribute_amount = 0;
            $deduction_amount = 0;
            $deductions = [];
            $contributions = [];
            $loans = [];
            $refunds = [];
            foreach ($settings as $setting) {
                if ($setting['type'] == 1) {
                    $cid = $setting['id'];
                    $s = $this->db->prepare("SELECT * FROM employee_contributions WHERE employee_id = ? AND contribution_id = ?");
                    $s->bind_param('is', $employee_id, $cid);
                    $s->execute();
                    $r = $s->get_result();
                    while ($row = $r->fetch_assoc()) {
                        $contribute_amount += $row['amount'];
                        $contributions[] = ["amount" => $row['amount'], "contribution_id" => (int) $row['contribution_id']];
                    }
                } elseif ($setting['type'] == 2) {
                    $did = $setting['id'];
                    $s = $this->db->prepare("SELECT * FROM employee_deductions WHERE employee_id = ? AND deduction_id = ?");
                    $s->bind_param('is', $employee_id, $did);
                    $s->execute();
                    $r = $s->get_result();
                    while ($row = $r->fetch_assoc()) {
                        if (!empty($row['effective_date']) && date('Y-m-d', strtotime($row['effective_date'])) > $date_to) continue;
                        $total = (float) $row['total_amount'];
                        if ($total > 0) {
                            if ((int) $row['status'] === 1) continue;
                            $balance = (float) $row['balance'];
                            if ($balance <= 0) continue;
                            $ded = (float) $row['amount'];
                            if ($balance < $ded) $ded = $balance;
                            $deduction_amount += $ded;
                            $deductions[] = ["amount" => $ded, "deduction_id" => (int) $row['deduction_id'], "type" => 1, "ded_row_id" => (int) $row['id'], "amortizing" => 1];
                        } else {
                            $deduction_amount += $row['amount'];
                            $deductions[] = ["amount" => $row['amount'], "deduction_id" => (int) $row['deduction_id'], "type" => 1];
                        }
                    }
                } elseif ($setting['type'] == 3) {
                    $clt = (int) $setting['id'];
                    $s = $this->db->prepare("SELECT * FROM loans WHERE employee_id = ? AND loan_type = ?");
                    $s->bind_param('is', $employee_id, $clt);
                    $s->execute();
                    $r = $s->get_result();
                    while ($row = $r->fetch_assoc()) {
                        // Deductions begin on effective_date when set, else on loan_date.
                        $loan_start = !empty($row['effective_date']) ? $row['effective_date'] : $row['loan_date'];
                        if (!empty($loan_start) && date('Y-m-d', strtotime($loan_start)) > $date_to) continue;
                        if ((int) $row['loan_status'] === 1) continue;
                        $balance = (float) $row['loan_balance'];
                        if ($balance <= 0) continue;
                        $damount = (float) $row['damount'];
                        if ($balance < $damount) $damount = $balance;
                        $loans[] = ["amount" => $damount, "deduction_id" => $row['loan_type'], "type" => 2];
                    }
                } elseif ($setting['type'] == 4) {
                    $refunds[] = ["amount" => 0, "refund_id" => (int) $setting['id']];
                }
            }

            $contributions_j = json_encode($contributions);
            $deductions_j = json_encode($deductions);
            $loans_j = json_encode($loans);
            $refunds_j = json_encode($refunds);

            // No attendance: hours/present/OT/late all zero; per_day is the daily rate.
            $salary = (float) $emp['salary'];
            $per_day = $salary;
            $per_minute = number_format($salary / 1440, 2);
            $basic_pay = (float) $emp['basic_pay'];
            $ot_rate = (float) $emp['ot_rate'];
            $sss_fund = (float) $emp['sss_fund'];
            $allowance_amount = (float) $emp['allowance_rate'];
            $zero = 0;
            $rate_type = 'fixed';
            // A salaried employee has no attendance here, but the payslip still
            // derives an hourly / per-minute rate from their daily rate — so their
            // shift length is frozen onto the item exactly like the DTR path.
            $day_hours = payroll_day_hours($this->db, $employee_id, $date_from);

            $ins = $this->db->prepare("INSERT INTO payroll_items
                (payroll_id, employee_id, salary, allowance_amount, contribute_amount,
                 deduction_amount, deductions, contributions, total_hours,
                 per_day, under_time, late, present, ot_rate, per_minute, ot, site_id, loans, basic_pay, sss_fund, refunds, sunday_duty, absent, rate_type, day_hours)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$ins) continue;
            $ins->bind_param(
                'sssssssssssssssssssssssss',
                $payroll_id, $employee_id, $salary, $allowance_amount, $contribute_amount,
                $deduction_amount, $deductions_j, $contributions_j, $zero,
                $per_day, $zero, $zero, $zero, $ot_rate, $per_minute, $zero, $first_site, $loans_j, $basic_pay, $sss_fund, $refunds_j, $zero, $zero, $rate_type, $day_hours
            );
            $ins->execute();
            $inserted++;
        }
        return $inserted;
    }

    function update_status_user()
    {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = isset($_POST['status']) ? $this->db->real_escape_string($_POST['status']) : '';
        if ($id) {
            $stmt = $this->db->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $status, $id);
            if ($stmt->execute()) {
                return ['result' => true, 'message' => 'updated'];
            } else {
                return ['result' => false, 'message' => $stmt->error];
            }
        } else {
            return ['result' => false, 'message' => 'Invalid parameters'];
        }
    }

    function update_status_dtr()
    {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $approved_by = $_SESSION['login_id'];
        $status = 2;
        if ($id) {
            // Block batch approval while any child record still has an undecided (pending) status.
            $pend = $this->db->query("SELECT COUNT(*) AS c FROM DTR_details WHERE ddtr_id = $id AND status = 0")->fetch_assoc();
            if ($pend && (int)$pend['c'] > 0) {
                return ['result' => false, 'message' => 'Cannot approve: ' . (int)$pend['c'] . ' record(s) still pending. Approve or disapprove all first.'];
            }
            // Block while any row's frozen shift disagrees with the current roster.
            // Status 2 is one-way — recompute_dtr refuses locked batches — so
            // approving over the stale-schedule banner would freeze figures the
            // roster no longer agrees with, permanently. Same detector as the
            // banner, so an admin is never blocked without a visible warning.
            $stale = $this->db->query("SELECT COUNT(*) AS c FROM DTR_details d
                WHERE d.ddtr_id = $id AND " . dtr_schedule_mismatch_where('d'))->fetch_assoc();
            if ($stale && (int)$stale['c'] > 0) {
                return ['result' => false, 'message' => 'Cannot approve: ' . (int)$stale['c'] . ' record(s) were computed under an outdated schedule. Run Recompute on this batch first.'];
            }
            $stmt = $this->db->prepare("UPDATE DTR SET status = ?, approved_by = ? WHERE id = ?");
            $stmt->bind_param('ssi', $status, $approved_by, $id);
            if ($stmt->execute()) {
                return ['result' => true, 'message' => 'updated'];
            } else {
                return ['result' => false, 'message' => $stmt->error];
            }
        } else {
            return ['result' => false, 'message' => 'Invalid parameters'];
        }
    }

    // Move a DTR into "Ready for Review" (status 3) and notify every employee on it
    // so they can confirm/dispute their own attendance in the portal before payroll.
    function send_dtr_for_review()
    {
        // The employee sign-off is an OPTIONAL step, off by default
        // (DTR_EMPLOYEE_REVIEW_ENABLED in db_connect.php). While it is off no
        // batch may be parked in status 3 by a stale page or a crafted request.
        if (!DTR_EMPLOYEE_REVIEW_ENABLED) {
            return ['result' => false, 'message' => 'Employee DTR review is turned off. Approve this DTR directly for payroll.'];
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id) return ['result' => false, 'message' => 'Invalid parameters'];

        $dtr = $this->db->query("SELECT id, date_from, date_to, status FROM DTR WHERE id = $id")->fetch_assoc();
        if (!$dtr) return ['result' => false, 'message' => 'DTR not found'];
        if ((int)$dtr['status'] === 2) {
            return ['result' => false, 'message' => 'This DTR is already approved.'];
        }

        // Don't send for review while records are still undecided by the timekeeper/admin.
        $pend = $this->db->query("SELECT COUNT(*) AS c FROM DTR_details WHERE ddtr_id = $id AND status = 0")->fetch_assoc();
        if ($pend && (int)$pend['c'] > 0) {
            return ['result' => false, 'message' => 'Approve or disapprove all ' . (int)$pend['c'] . ' pending record(s) before sending for review.'];
        }

        $upd = $this->db->query("UPDATE DTR SET status = 3 WHERE id = $id");
        if (!$upd) return ['result' => false, 'message' => $this->db->error];

        // Fresh review round — clear any prior sign-offs for this batch.
        $this->db->query("DELETE FROM dtr_employee_reviews WHERE ddtr_id = $id");

        $period = date('M j', strtotime($dtr['date_from'])) . ' – ' . date('M j, Y', strtotime($dtr['date_to']));
        $count = $this->notifyEmployeesFromQuery(
            "SELECT DISTINCT employee_id FROM DTR_details WHERE ddtr_id = $id",
            'DTR ready for your review',
            "Your attendance for $period is ready. Please review and confirm before payroll processing.",
            'ri-file-list-3-line',
            'warning',
            'employee-portal.php?tab=mydtr'
        );

        return ['result' => true, 'message' => "Sent for review. $count employee(s) notified."];
    }

    // Review progress for a DTR batch: total employees, confirmed, disputed, pending.
    function dtr_review_progress()
    {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id) return ['result' => false, 'message' => 'Invalid parameters'];

        $total = (int) ($this->db->query("SELECT COUNT(DISTINCT employee_id) AS c FROM DTR_details WHERE ddtr_id = $id")->fetch_assoc()['c'] ?? 0);
        $conf  = (int) ($this->db->query("SELECT COUNT(*) AS c FROM dtr_employee_reviews WHERE ddtr_id = $id AND status = 1")->fetch_assoc()['c'] ?? 0);
        $disp  = (int) ($this->db->query("SELECT COUNT(*) AS c FROM dtr_employee_reviews WHERE ddtr_id = $id AND status = 2")->fetch_assoc()['c'] ?? 0);

        $rows = [];
        $res = $this->db->query("SELECT r.employee_id, r.status, r.comment, r.reviewed_at,
                CONCAT(e.lastname, ', ', e.firstname) AS name
            FROM dtr_employee_reviews r
            INNER JOIN employee e ON e.id = r.employee_id
            WHERE r.ddtr_id = $id
            ORDER BY r.status DESC, r.reviewed_at DESC");
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

        return [
            'result' => true,
            'total' => $total,
            'confirmed' => $conf,
            'disputed' => $disp,
            'pending' => max(0, $total - $conf - $disp),
            'rows' => $rows,
        ];
    }

    // ── Round 2: dispute lifecycle, bulk send, reminders, exports ──────────────

    // Admin resolves one disputed review (DTR or payroll) with a written reply,
    // and the filing employee is notified of the outcome.
    // Params: type = 'dtr'|'payroll', id = reviews-table PK, reply = admin note.
    /**
     * Mark an employee's sign-off message as read by staff. Fired when the
     * message popup opens, so the UNREAD dot clears without needing a reply —
     * reading a dispute is not the same as resolving it. First reader wins;
     * later opens leave the original timestamp alone.
     */
    function mark_review_seen()
    {
        $type = ($_POST['type'] ?? '') === 'payroll' ? 'payroll' : 'dtr';
        $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id) return ['result' => false, 'message' => 'Missing review id'];

        $table = $type === 'payroll' ? 'payroll_employee_reviews' : 'dtr_employee_reviews';
        // Older databases may not have run 2026_07_review_seen.sql yet — the
        // panels degrade to "everything read" rather than erroring.
        $col = $this->db->query("SHOW COLUMNS FROM $table LIKE 'seen_at'");
        if (!$col || !$col->num_rows) return ['result' => true, 'message' => 'skipped'];

        $admin = (int) ($_SESSION['login_id'] ?? 0);
        $stmt = $this->db->prepare("UPDATE $table SET seen_at = NOW(), seen_by = ?,
                                    reviewed_at = reviewed_at WHERE id = ? AND seen_at IS NULL");
        $stmt->bind_param('ii', $admin, $id);
        if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];

        return ['result' => true, 'message' => 'seen', 'at' => date('M j, g:i A')];
    }

    function resolve_review_dispute()
    {
        $type  = ($_POST['type'] ?? '') === 'payroll' ? 'payroll' : 'dtr';
        $id    = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $reply = trim($_POST['reply'] ?? '');
        $admin = (int) ($_SESSION['login_id'] ?? 0);
        if (!$id || $reply === '') return ['result' => false, 'message' => 'A reply is required to resolve a dispute.'];

        $table = $type === 'payroll' ? 'payroll_employee_reviews' : 'dtr_employee_reviews';
        $batchCol = $type === 'payroll' ? 'payroll_id' : 'ddtr_id';

        $rv = $this->db->query("SELECT * FROM $table WHERE id = $id")->fetch_assoc();
        if (!$rv) return ['result' => false, 'message' => 'Review not found'];
        // Disputes AND confirmations can be answered: an employee who signs off
        // with "correct, but move my loan to next cutoff" deserves a reply too.
        // Only a sign-off that carries no message has nothing to respond to.
        $rvStatus = (int)$rv['status'];
        if ($rvStatus !== 1 && $rvStatus !== 2) return ['result' => false, 'message' => 'This employee has not reviewed yet.'];
        $isDispute = $rvStatus === 2;
        if (!$isDispute && trim((string)($rv['comment'] ?? '')) === '') {
            return ['result' => false, 'message' => 'This employee confirmed without a message — there is nothing to reply to.'];
        }

        // reviewed_at = reviewed_at guards against schemas where the column still
        // has ON UPDATE CURRENT_TIMESTAMP: resolving must not overwrite when the
        // employee actually signed off.
        // Replying implies reading, so clear the UNREAD marker in the same write
        // (guarded — 2026_07_review_seen.sql may not have run yet).
        $hasSeen = $this->db->query("SHOW COLUMNS FROM $table LIKE 'seen_at'");
        $seenSet = ($hasSeen && $hasSeen->num_rows)
            ? ", seen_at = COALESCE(seen_at, NOW()), seen_by = COALESCE(seen_by, $admin)" : '';
        $stmt = $this->db->prepare("UPDATE $table SET resolved_at = NOW(), resolved_by = ?, admin_reply = ?$seenSet, reviewed_at = reviewed_at WHERE id = ?");
        $stmt->bind_param('isi', $admin, $reply, $id);
        if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];

        // Period + portal link for the employee notification.
        $batchId = (int) $rv[$batchCol];
        if ($type === 'payroll') {
            $b = $this->db->query("SELECT date_from, date_to FROM payroll WHERE id = $batchId")->fetch_assoc();
            $link = 'employee-portal.php?tab=payslips';
            $what = 'payslip';
        } else {
            $b = $this->db->query("SELECT date_from, date_to FROM DTR WHERE id = $batchId")->fetch_assoc();
            $link = 'employee-portal.php?tab=mydtr';
            $what = 'DTR';
        }
        $period = $b ? (date('M j', strtotime($b['date_from'])) . ' – ' . date('M j, Y', strtotime($b['date_to']))) : '';
        $this->notifyEmployee(
            (int) $rv['employee_id'],
            $isDispute ? 'Your dispute was addressed' : 'HR replied to your message',
            $isDispute
                ? "HR responded to your $what dispute for $period: $reply"
                : "HR replied to your note on your $what for $period: $reply",
            'ri-chat-check-line',
            'info',
            $link
        );

        return [
            'result'  => true,
            'message' => $isDispute
                ? 'Dispute resolved and the employee has been notified.'
                : 'Reply sent and the employee has been notified.',
        ];
    }

    // Bulk-send several DTR batches for review (status 1 → 3). ids = array of DTR ids.
    function bulk_send_dtr_for_review()
    {
        if (!DTR_EMPLOYEE_REVIEW_ENABLED) {
            return ['result' => false, 'message' => 'Employee DTR review is turned off. Approve these DTRs directly for payroll.'];
        }

        $ids = $this->_intIds($_POST['ids'] ?? []);
        if (!$ids) return ['result' => false, 'message' => 'No DTR batches selected.'];

        $sent = 0;
        $skipped = [];
        foreach ($ids as $id) {
            $dtr = $this->db->query("SELECT id, date_from, date_to, status FROM DTR WHERE id = $id")->fetch_assoc();
            if (!$dtr || (int)$dtr['status'] !== 1) {
                $skipped[] = $id;
                continue;
            }
            $pend = $this->db->query("SELECT COUNT(*) AS c FROM DTR_details WHERE ddtr_id = $id AND status = 0")->fetch_assoc();
            if ($pend && (int)$pend['c'] > 0) {
                $skipped[] = $id;
                continue;
            }

            $this->db->query("UPDATE DTR SET status = 3 WHERE id = $id");
            $this->db->query("DELETE FROM dtr_employee_reviews WHERE ddtr_id = $id");
            $period = date('M j', strtotime($dtr['date_from'])) . ' – ' . date('M j, Y', strtotime($dtr['date_to']));
            $this->notifyEmployeesFromQuery(
                "SELECT DISTINCT employee_id FROM DTR_details WHERE ddtr_id = $id",
                'DTR ready for your review',
                "Your attendance for $period is ready. Please review and confirm before payroll processing.",
                'ri-file-list-3-line',
                'warning',
                'employee-portal.php?tab=mydtr'
            );
            $sent++;
        }
        $msg = "$sent DTR batch(es) sent for review.";
        if ($skipped) $msg .= ' ' . count($skipped) . ' skipped (not computed or still pending).';
        return ['result' => true, 'message' => $msg, 'sent' => $sent, 'skipped' => $skipped];
    }

    // Bulk-send several payroll batches for review (status 1 → 3). ids = array of payroll ids.
    function bulk_send_payroll_for_review()
    {
        $ids = $this->_intIds($_POST['ids'] ?? []);
        if (!$ids) return ['result' => false, 'message' => 'No payroll batches selected.'];

        $sent = 0;
        $skipped = [];
        foreach ($ids as $id) {
            $p = $this->db->query("SELECT id, date_from, date_to, status FROM payroll WHERE id = $id")->fetch_assoc();
            if (!$p || (int)$p['status'] !== 1) {
                $skipped[] = $id;
                continue;
            }

            $this->db->query("UPDATE payroll SET status = 3 WHERE id = $id");
            $this->db->query("DELETE FROM payroll_employee_reviews WHERE payroll_id = $id");
            $period = date('M j', strtotime($p['date_from'])) . ' – ' . date('M j, Y', strtotime($p['date_to']));
            $this->notifyEmployeesFromQuery(
                "SELECT DISTINCT employee_id FROM payroll_items WHERE payroll_id = $id",
                'Payslip ready for your review',
                "Your payslip for $period is ready. Please review and confirm before it's locked.",
                'ri-file-list-3-line',
                'warning',
                'employee-portal.php?tab=payslips'
            );
            $this->save_payroll_history($id, 5, 'Send for Review');
            $sent++;
        }
        $msg = "$sent payroll batch(es) sent for review.";
        if ($skipped) $msg .= ' ' . count($skipped) . ' skipped (not computed).';
        return ['result' => true, 'message' => $msg, 'sent' => $sent, 'skipped' => $skipped];
    }

    // Re-notify only the employees on a batch who have NOT reviewed yet (still pending).
    function remind_dtr_review()
    {
        if (!DTR_EMPLOYEE_REVIEW_ENABLED) {
            return ['result' => false, 'message' => 'Employee DTR review is turned off.'];
        }
        return $this->_remindReview('dtr');
    }
    function remind_payroll_review()
    {
        return $this->_remindReview('payroll');
    }

    // Per-payroll-item review-request counters. Added on demand so existing
    // databases pick them up without a manual migration.
    private function ensureReviewSentColumns()
    {
        $has = $this->db->query("SHOW COLUMNS FROM payroll_items LIKE 'review_sent_count'");
        if ($has && $has->num_rows > 0) return true;
        $this->db->query("ALTER TABLE payroll_items
            ADD COLUMN review_sent_count INT NOT NULL DEFAULT 0,
            ADD COLUMN review_sent_at DATETIME NULL");
        $chk = $this->db->query("SHOW COLUMNS FROM payroll_items LIKE 'review_sent_count'");
        return $chk && $chk->num_rows > 0;
    }

    // Ask a SUBSET of a payroll's employees to review their payslip.
    // Unlike send_payroll_for_review() this does NOT change the batch status —
    // it only notifies the picked employees, so a reviewer can chase individual
    // people while the batch is still being worked on. Repeatable: each send
    // bumps that employee's review_sent_count.
    function notify_payroll_review_selected()
    {
        $id  = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $raw = $_POST['item_ids'] ?? '';
        if (is_array($raw)) $raw = implode(',', $raw);
        $itemIds = array_values(array_filter(array_map('intval', explode(',', (string) $raw))));
        if (!$id || !$itemIds) return ['result' => false, 'message' => 'Select at least one employee first.'];

        $payroll = $this->db->query("SELECT id, date_from, date_to, status FROM payroll WHERE id = $id")->fetch_assoc();
        if (!$payroll) return ['result' => false, 'message' => 'Payroll not found.'];
        // Employees can only SEE and confirm a payslip while the batch is out
        // for review (status 3) — see employee-portal.php. Refuse to notify in
        // any other state so a request can never point at an empty portal.
        $pstatus = (int) $payroll['status'];
        if ($pstatus !== 3) {
            $why = [
                0 => 'Calculate this payroll first, then send it for review.',
                1 => 'Send this payroll for review first — employees cannot see their payslip yet.',
                2 => 'This payroll is locked; the confirmation window is closed.',
            ][$pstatus] ?? 'This payroll is not open for employee review.';
            return ['result' => false, 'message' => $why];
        }

        // Scope the ids to this payroll so a crafted request can't notify others.
        $idList = implode(',', $itemIds);
        $period = date('M j', strtotime($payroll['date_from'])) . ' – ' . date('M j, Y', strtotime($payroll['date_to']));
        $count  = $this->notifyEmployeesFromQuery(
            "SELECT DISTINCT employee_id FROM payroll_items WHERE payroll_id = $id AND id IN ($idList)",
            'Please review your payslip',
            "Your payslip for $period needs your review. Please check it and confirm.",
            'ri-file-list-3-line',
            'warning',
            'employee-portal.php?tab=payslips'
        );
        if ($count <= 0) return ['result' => false, 'message' => 'No matching employees in this payroll.'];

        // Bump the per-employee send counter and return the fresh values so the
        // UI can update its badges without a reload.
        $sent = [];
        if ($this->ensureReviewSentColumns()) {
            $this->db->query("UPDATE payroll_items
                SET review_sent_count = review_sent_count + 1, review_sent_at = NOW()
                WHERE payroll_id = $id AND id IN ($idList)");
            $res = $this->db->query("SELECT id, review_sent_count, review_sent_at
                FROM payroll_items WHERE payroll_id = $id AND id IN ($idList)");
            if ($res) while ($r = $res->fetch_assoc()) {
                $sent[(int) $r['id']] = [
                    'n'  => (int) $r['review_sent_count'],
                    'at' => $r['review_sent_at'] ? date('M j, g:i A', strtotime($r['review_sent_at'])) : '',
                ];
            }
        }

        // type 5 appends " Payroll" to the phrase (see save_payroll_history)
        $this->save_payroll_history($id, 5, "Review Request Sent to $count Employee(s) —");
        return [
            'result'  => true,
            'message' => "$count employee(s) notified to review their payslip.",
            'sent'    => $sent,
        ];
    }

    private function _remindReview($type)
    {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id) return ['result' => false, 'message' => 'Invalid parameters'];

        if ($type === 'payroll') {
            $b = $this->db->query("SELECT date_from, date_to, status FROM payroll WHERE id = $id")->fetch_assoc();
            $itemsTbl = 'payroll_items';
            $itemsKey = 'payroll_id';
            $revTbl = 'payroll_employee_reviews';
            $revKey = 'payroll_id';
            $title = 'Reminder: review your payslip';
            $link = 'employee-portal.php?tab=payslips';
            $what = 'payslip';
        } else {
            $b = $this->db->query("SELECT date_from, date_to, status FROM DTR WHERE id = $id")->fetch_assoc();
            $itemsTbl = 'DTR_details';
            $itemsKey = 'ddtr_id';
            $revTbl = 'dtr_employee_reviews';
            $revKey = 'ddtr_id';
            $title = 'Reminder: review your DTR';
            $link = 'employee-portal.php?tab=mydtr';
            $what = 'DTR';
        }
        if (!$b || (int)$b['status'] !== 3) return ['result' => false, 'message' => 'This batch is not open for review.'];

        $period = date('M j', strtotime($b['date_from'])) . ' – ' . date('M j, Y', strtotime($b['date_to']));
        $count = $this->notifyEmployeesFromQuery(
            "SELECT DISTINCT i.employee_id
            FROM $itemsTbl i
            LEFT JOIN $revTbl r ON r.$revKey = i.$itemsKey AND r.employee_id = i.employee_id
            WHERE i.$itemsKey = $id AND r.id IS NULL",
            $title,
            "Please review and confirm your $what for $period. It's still awaiting your response.",
            'ri-notification-badge-line',
            'warning',
            $link
        );
        return ['result' => true, 'message' => $count > 0 ? "Reminder sent to $count employee(s)." : 'Everyone has already reviewed — no reminders sent.'];
    }

    // Stream a per-batch review log as CSV (employee, decision, comment, timestamps, reply).
    function export_dtr_reviews()
    {
        $this->_exportReviews('dtr');
    }
    function export_payroll_reviews()
    {
        $this->_exportReviews('payroll');
    }

    private function _exportReviews($type)
    {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($type === 'payroll') {
            $tbl = 'payroll_employee_reviews';
            $key = 'payroll_id';
            $label = 'payroll';
        } else {
            $tbl = 'dtr_employee_reviews';
            $key = 'ddtr_id';
            $label = 'dtr';
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $label . '_review_log_' . $id . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Employee', 'Decision', 'Comment', 'Reviewed At', 'Resolved At', 'HR Reply']);
        if ($id) {
            $res = $this->db->query("SELECT CONCAT(e.lastname, ', ', e.firstname) AS name,
                    r.status, r.comment, r.reviewed_at, r.resolved_at, r.admin_reply
                FROM $tbl r INNER JOIN employee e ON e.id = r.employee_id
                WHERE r.$key = $id ORDER BY r.status DESC, r.reviewed_at DESC");
            if ($res) while ($r = $res->fetch_assoc()) {
                $decision = (int)$r['status'] === 1 ? 'Confirmed' : ((int)$r['status'] === 2 ? 'Disputed' : 'Pending');
                fputcsv($out, [$r['name'], $decision, $r['comment'], $r['reviewed_at'], $r['resolved_at'], $r['admin_reply']]);
            }
        }
        fclose($out);
    }

    // Normalise a posted ids value (array or comma string) to a clean int list.
    private function _intIds($raw)
    {
        if (is_string($raw)) $raw = explode(',', $raw);
        if (!is_array($raw)) return [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), function ($v) {
            return $v > 0;
        })));
        return $ids;
    }

    function save_payroll()
    {

        $pid = null;

        // Parse the form filters first — needed to look up the default sites
        // when the user submits without ticking any checkbox.
        $decodedQueryString2 = urldecode($_POST['form_data']);
        // Parse the query string into an associative array
        parse_str($decodedQueryString2, $resultArray2);
        $id = $resultArray2["id"];
        $p2 = $resultArray2["p2"];
        $date_from = $resultArray2["date_from"];
        $date_to = $resultArray2["date_to"];
        $type = $resultArray2["type"];
        // Employer selection removed from the form — every payroll uses the
        // default employer (1). The column stays for legacy rows/reports.
        $employer_id = (int) ($resultArray2["employer_id"] ?? 1);
        $category = $resultArray2["category_id"] ?? 1;

        $site_ids = $_POST['site_ids'] ?? '';
        $decodedQueryString = urldecode($site_ids);
        parse_str($decodedQueryString, $resultArray);
        $selected_sites = (!empty($resultArray["site_ids"]) && is_array($resultArray["site_ids"]))
            ? $resultArray["site_ids"]
            : [];

        // No site ticked → default to ALL active sites in the database
        // (not filtered by the form's employer / date / cluster params).
        if (count($selected_sites) === 0) {
            $selected_sites = $this->getAllSiteIds();
            if (count($selected_sites) === 0) {
                return ['result' => false, 'message' => 'No sites found in the database.'];
            }
        }

        // Normalise to integers so the stored JSON is clean.
        $selected_sites = array_values(array_unique(array_map('intval', $selected_sites)));
        $jsonString = json_encode($selected_sites);
        //$deferential = $resultArray2["deferential"];
        $deferential = isset($deferential) ? 2 : 1;
        $data = " date_from='$date_from' ";
        $data .= ", date_to = '$date_to' ";
        $data .= ", type = '$type' ";
        // $data .= ", deferential = '$deferential' ";
        $data .= ", site_ids = '$jsonString' ";
        $data .= ", employer_id = '$employer_id' ";
        $data .= ", category = '$category' ";
        $data .= ", p2 = '$p2' ";
        if (empty($id)) {
            // Refuse a second run for a cutoff that already has one. Without
            // this, a double-clicked Create (or two concurrent requests) built
            // two full payrolls for the same period, each with its own payslip
            // set — the same employee paid twice, with nothing to indicate which
            // run was the real one.
            $dupFrom = $this->db->real_escape_string($date_from);
            $dupTo   = $this->db->real_escape_string($date_to);
            $dupType = (int) $type;
            $dupCat  = (int) $category;
            $dup = $this->db->query(
                "SELECT id, ref_no FROM payroll
                 WHERE date_from = '$dupFrom' AND date_to = '$dupTo'
                   AND type = $dupType AND category = $dupCat
                 ORDER BY id DESC LIMIT 1"
            );
            if ($dup && ($dupRow = $dup->fetch_assoc())) {
                return [
                    'result'  => false,
                    'message' => 'A payroll for ' . $date_from . ' to ' . $date_to
                               . ' already exists (ref ' . $dupRow['ref_no'] . '). Open or delete it instead of creating a second one.',
                    'id'      => (int) $dupRow['id'],
                ];
            }

            // ref_no must be unique. The retry loop below only protects the
            // serial case; the UNIQUE index added on payroll.ref_no is what
            // stops two concurrent creates from landing the same number.
            $i = 1;
            $attempts = 0;
            while ($i == 1 && $attempts < 50) {
                $attempts++;
                $ref_no = date('Y') . '-' . mt_rand(1, 9999);

                $chk = $this->db->query("SELECT id FROM payroll where ref_no = '$ref_no' ")->num_rows;

                if ($chk <= 0) {
                    $i = 0;
                }
            }
            $data .= ", ref_no='$ref_no' ";
            // Settings (which contributions/deductions/loans/refunds to apply) are
            // now chosen in the Create modal. Use what was ticked. Only fall back to
            // ALL available when the form didn't offer the checkboxes at all
            // (settings_offered marker absent) — an admin who deliberately unticks
            // everything gets a no-deduction payroll, not every deduction.
            $chosen = $this->settingsFromInput($resultArray2);
            if (empty($chosen) && empty($resultArray2['settings_offered'])) {
                $chosen = $this->defaultPayrollSettings();
            }
            $settings_json = $this->db->real_escape_string(json_encode($chosen));
            $data .= ", settings='$settings_json' ";
            $save = $this->db->query("INSERT INTO payroll set " . $data);
            $pid = $this->db->insert_id;
            $this->save_payroll_history($this->db->insert_id, 1);
        } else {
            $save = $this->db->query("UPDATE payroll set " . $data . " where id=" . $id);
        }
        if ($save) {
            // Auto-calculate a freshly created payroll so Create lands directly
            // on "Calculated" — no separate Calculate step. DTR is finalized
            // before a payroll is created, so the numbers are ready. A fresh calc
            // (no 'type') is side-effect-free; loans don't commit until Lock.
            if (!empty($pid)) {
                $savedPost = $_POST;
                $_POST['id'] = $pid;
                unset($_POST['type']); // fresh calculate, not a recalculate
                try {
                    $this->calculate_payroll();
                } catch (Exception $e) {
                    // Leave the payroll at status 0; it can be calculated manually.
                    error_log('Auto-calculate on create failed: ' . $e->getMessage());
                }
                $_POST = $savedPost;
            }
            return ['result' => true, 'message' => 'save', 'id' =>  $pid];
        }
    }

    // Builds the default payroll "settings" = every available contribution,
    // deduction, loan and refund (i.e. as if every box in the Settings modal is
    // ticked), so a newly created payroll auto-calculates a complete payslip.
    // Mirrors the option lists rendered in component/add_payroll.php; the type
    // codes match save_payroll_settings(): 1=contribution, 2=deduction, 3=loan,
    // 4=refund.
    function defaultPayrollSettings()
    {
        $settings = [];
        $sources = [
            1 => "SELECT id FROM contributions",
            3 => "SELECT clt_id AS id FROM contribution_loan_types",
            2 => "SELECT id FROM deductions",
            4 => "SELECT id FROM refunds",
        ];
        foreach ($sources as $type => $sql) {
            $res = $this->db->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $settings[] = ["id" => $r['id'], "type" => $type];
                }
            }
        }
        return $settings;
    }

    // Builds a payroll "settings" array from posted checkbox groups (as parsed
    // from the Create form). Mirrors save_payroll_settings() type codes:
    // 1=contribution, 2=deduction, 3=loan, 4=refund.
    function settingsFromInput($src)
    {
        $settings = [];
        $map = [1 => 'contributions', 3 => 'loans', 2 => 'deductions', 4 => 'refunds'];
        foreach ($map as $type => $key) {
            if (!empty($src[$key]) && is_array($src[$key])) {
                foreach ($src[$key] as $v) {
                    $settings[] = ["id" => $v, "type" => $type];
                }
            }
        }
        return $settings;
    }

    // Returns the IDs of every active site in the database. Used by
    // save_payroll() to default to "all sites" when the user ticks none.
    function getAllSiteIds()
    {
        $sites = $this->db->query("SELECT id FROM sites WHERE status = 1");
        $ids = [];
        if ($sites) {
            while ($row = $sites->fetch_assoc()) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    function get_sites()
    {
        // Assuming you have a valid DB connection in $this->db
        $employer_id = $_POST['employer_id'];
        $category_id = $_POST['category_id'];
        $date_from = date("Y-m-d", strtotime($_POST['date_from']));
        $date_to = date("Y-m-d", strtotime($_POST['date_to']));

        $filter_query = "";
        $disabled = false;
        if ($category_id != 0) {
            $disabled = true;
            $filter_query = "AND sites.cluster_id  = $category_id ";
        }
        $sites = $this->db->query("
            SELECT 
                        sites.*, 
                        users.name, 
                        clusters.cluster,
                         DTR.date_from,
                          DTR.date_to
                    FROM users
                    INNER JOIN sites 
                        ON users.id = sites.timekeeper_id
                    LEFT JOIN clusters 
                        ON clusters.id = sites.cluster_id
                    INNER JOIN DTR 
                        ON DTR.site_id = sites.id
                    WHERE users.role = 5
                    AND sites.status = 1
                    AND sites.employer_id = '$employer_id'
                    AND DTR.date_from BETWEEN '$date_from' AND '$date_to'
                    AND DTR.status = 2 
                    $filter_query
                    GROUP BY sites.id
        ");



        // Start outputting the table with Bootstrap classes
        echo '<div class="container mt-5">';
        echo '<table class="table table-bordered">';
        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col">Select</th>';
        echo '<th scope="col">Site</th>';
        echo '<th scope="col">Cluster</th>';
        echo '<th scope="col">Timekeeper</th>';
        echo '<th scope="col">Approved DTR</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Loop through the rows and create table rows with checkboxes
        while ($row = $sites->fetch_assoc()) {
            echo '<tr>';
            echo '<td class="text-center"><input type="checkbox" name="site_ids[]" value="' . $row['id'] . '"' . ($disabled ? ' onclick="return false;" checked ' : '') . '></td>';
            echo '<td><b><span class="text-primary">(' . htmlspecialchars($row['site_code']) . ')</span>' . htmlspecialchars($row['site_name']) . '</b><p>' . htmlspecialchars($row['site_address']) . '</p></td>';
            echo '<td>' . htmlspecialchars($row['cluster']) . '</td>';
            echo '<td>' . htmlspecialchars($row['name']) . '</td>';
            echo '<td>'
                . date("F d, Y", strtotime($row['date_from']))
                . ' - '
                . date("F d, Y", strtotime($row['date_to']))
                . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    function save_payroll_settings()
    {
        $settings = [];
        $count = 0;
        $contributions = isset($_POST['contributions']) && is_array($_POST['contributions']) ? $_POST['contributions'] : [];
        foreach ($contributions as $i =>  $k) {
            $settings[$count]["id"] = $k;
            $settings[$count]["type"] = 1;
            $count++;
        }

        $loans = isset($_POST['loans']) && is_array($_POST['loans']) ? $_POST['loans'] : [];
        foreach ($loans as $i =>  $k) {
            $settings[$count]["id"] = $k;
            $settings[$count]["type"] = 3;
            $count++;
        }

        $deductions = isset($_POST['deductions']) && is_array($_POST['deductions']) ? $_POST['deductions'] : [];
        foreach ($deductions as $i =>  $k) {
            $settings[$count]["id"] = $k;
            $settings[$count]["type"] = 2;
            $count++;
        }

        $refunds = isset($_POST['refunds']) && is_array($_POST['refunds']) ? $_POST['refunds'] : [];
        foreach ($refunds as $i =>  $k) {
            $settings[$count]["id"] = $k;
            $settings[$count]["type"] = 4;
            $count++;
        }



        $id = $_POST['id'];
        $settings_json =  json_encode($settings);
        $stmt = $this->db->prepare("UPDATE payroll SET settings = ? WHERE id = ?");
        $stmt->bind_param('si', $settings_json, $id);
        if ($stmt->execute()) {
            return ['result' => true, 'message' => 'updated'];
        } else {
            return ['result' => false, 'message' => $stmt->error];
        }
    }

    function delete_dtr_logs()
    {
        extract($_POST);
        $delete = $this->db->query("DELETE FROM DTR_details where id = " . $id);
        if ($delete) {
            return ['result' => true, 'message' => 'deleted'];
        } else {
            return ['result' => false, 'message' => 'Error while deleting'];
        }
    }

    function save_employee_attendance222()
    {
        extract($_POST);
        foreach ($employee_id as $k => $v) {
            $datetime_log[$k] = date("Y-m-d H:i", strtotime($datetime_log[$k]));
            $data = " employee_id='$employee_id[$k]' ";
            $data .= ", log_type = '$log_type[$k]' ";
            $data .= ", datetime_log = '$datetime_log[$k]' ";
            $save[] = $this->db->query("INSERT INTO attendance set " . $data);
        }
        if (isset($save)) {
            return 1;
        }
    }

    function save_employee_attendance()
    {
        $this->db->begin_transaction();
        try {
            $id = $_POST['id'];
            $employee_id = $_POST['employee_id'];
            $date_time = $_POST['date_time'];
            $datetime_log = $_POST['datetime_log'];
            $notes = trim($_POST['notes'] ?? '');
            $query = "SELECT  * FROM DTR_details
        WHERE ddtr_id = ? AND employee_id = ? AND date_time = ? ";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("iis", $id, $employee_id, $date_time);
            $stmt->execute();
            $result = $stmt->get_result();
            $details = $result->fetch_assoc();
            if (isset($details)) {
                $new_logs = [];
                $logs = json_decode($details['logs'], true);
                foreach ($datetime_log as $k => $log) {
                    $new_logs[$k]['dateTime'] =  $date_time . ' ' . $log;
                    $new_logs[$k]['type'] =  'manual';
                }
                $updated_logs =  array_merge($logs, $new_logs);
                $query_update = "UPDATE DTR_details SET logs = ?, notes = ? WHERE id = ?";
                $stmt3 = $this->db->prepare($query_update);
                if ($stmt3 === false) {
                    throw new Exception('Failed to prepare the statement: ' . $this->db->error);
                }
                $encoded_logs = json_encode($updated_logs);
                $stmt3->bind_param("ssi", $encoded_logs, $notes, $details['id']);
                try {
                    $stmt3->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to update data: ' . $e->getMessage());
                }
            } else {
                $hours = 0;
                $overtime = 0;
                $attendance_type = 'manual';
                $new_logs = [];
                foreach ($datetime_log as $k => $log) {
                    $new_logs[$k]['dateTime'] =  $date_time . ' ' . $log;
                    $new_logs[$k]['type'] =  'manual';
                }
                $logs = json_encode($new_logs);
                // Same frozen-shift stamp the other insert paths apply.
                $cs = dtr_compute_day($this->db, (int)$employee_id, date('Y-m-d', strtotime($date_time)), []);
                $sql2 = "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, overtime, notes,
                                                  schedule_id, day_hours, is_rest_day, sched_start, sched_end, sched_break, sched_graveyard)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt2 = $this->db->prepare($sql2);
                $stmt2->bind_param('ssssssssidissii', $id, $employee_id, $date_time, $hours, $logs, $attendance_type, $overtime, $notes,
                                   $cs['schedule_id'], $cs['day_hours'], $cs['is_rest_day'],
                                   $cs['sched_start'], $cs['sched_end'], $cs['sched_break'], $cs['sched_graveyard']);
                try {
                    $stmt2->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to insert data');
                }
            }
            $this->db->commit();
            return ['result' => true, 'message' => 'save'];
        } catch (mysqli_sql_exception $e) {
            return ['result' => false, 'message' => $e->getMessage()];
        }
        return ['result' => false, 'message' => 'save'];
    }

    function update_dtr_logs()
    {
        $this->db->begin_transaction();
        $id = $_POST['id'];
        if (isset($_POST['work_hours'])) {
            try {
                // Edited figures void any earlier decision — back to pending for re-approval.
                $query_update = "UPDATE DTR_details SET work_hours = ?, status = 0, decision_note = NULL, decided_by = NULL, decided_at = NULL WHERE id = ?";
                $stmt3 = $this->db->prepare($query_update);
                if ($stmt3 === false) {
                    throw new Exception('Failed to prepare the statement: ' . $this->db->error);
                }
                $stmt3->bind_param("si", $_POST['work_hours'], $id);
                try {
                    $stmt3->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to update data: ' . $e->getMessage());
                }
                $this->db->commit();
                return ['result' => true, 'message' => 'save'];
            } catch (mysqli_sql_exception $e) {
                return ['result' => false, 'message' => $e->getMessage()];
            }
            return ['result' => false, 'message' => 'save'];
        }

        if (isset($_POST['overtime'])) {
            try {
                $query_update = "UPDATE DTR_details SET overtime = ?, status = 0, decision_note = NULL, decided_by = NULL, decided_at = NULL WHERE id = ?";
                $stmt3 = $this->db->prepare($query_update);
                if ($stmt3 === false) {
                    throw new Exception('Failed to prepare the statement: ' . $this->db->error);
                }
                $stmt3->bind_param("si", $_POST['overtime'], $id);
                try {
                    $stmt3->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to update data: ' . $e->getMessage());
                }
                $this->db->commit();
                return ['result' => true, 'message' => 'save'];
            } catch (mysqli_sql_exception $e) {
                return ['result' => false, 'message' => $e->getMessage()];
            }
            return ['result' => false, 'message' => 'save'];
        }

        if (isset($_POST['undertime'])) {
            try {
                $query_update = "UPDATE DTR_details SET undertime = ?, status = 0, decision_note = NULL, decided_by = NULL, decided_at = NULL WHERE id = ?";
                $stmt3 = $this->db->prepare($query_update);
                if ($stmt3 === false) {
                    throw new Exception('Failed to prepare the statement: ' . $this->db->error);
                }
                $stmt3->bind_param("si", $_POST['undertime'], $id);
                try {
                    $stmt3->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to update data: ' . $e->getMessage());
                }
                $this->db->commit();
                return ['result' => true, 'message' => 'save'];
            } catch (mysqli_sql_exception $e) {
                return ['result' => false, 'message' => $e->getMessage()];
            }
            return ['result' => false, 'message' => 'save'];
        }

        if (isset($_POST['late'])) {
            try {
                $query_update = "UPDATE DTR_details SET late = ?, status = 0, decision_note = NULL, decided_by = NULL, decided_at = NULL WHERE id = ?";
                $stmt3 = $this->db->prepare($query_update);
                if ($stmt3 === false) {
                    throw new Exception('Failed to prepare the statement: ' . $this->db->error);
                }
                $stmt3->bind_param("si", $_POST['late'], $id);
                try {
                    $stmt3->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to update data: ' . $e->getMessage());
                }
                $this->db->commit();
                return ['result' => true, 'message' => 'save'];
            } catch (mysqli_sql_exception $e) {
                return ['result' => false, 'message' => $e->getMessage()];
            }
            return ['result' => false, 'message' => 'save'];
        }

        if (isset($_POST['notes'])) {
            try {
                $notes = trim($_POST['notes']);
                $query_update = "UPDATE DTR_details SET notes = ? WHERE id = ?";
                $stmt3 = $this->db->prepare($query_update);
                if ($stmt3 === false) {
                    throw new Exception('Failed to prepare the statement: ' . $this->db->error);
                }
                $stmt3->bind_param("si", $notes, $id);
                try {
                    $stmt3->execute();
                } catch (Exception $e) {
                    throw new Exception('Failed to update data: ' . $e->getMessage());
                }
                $this->db->commit();
                return ['result' => true, 'message' => 'save'];
            } catch (mysqli_sql_exception $e) {
                return ['result' => false, 'message' => $e->getMessage()];
            }
            return ['result' => false, 'message' => 'save'];
        }
    }



    function save_biometric_attendance()
    {
        $employee_id = intval($_POST['employee_id'] ?? 0);
        $scan_time   = trim($_POST['scan_time'] ?? '');

        // Single-site deployment: every scan belongs to site 1, so a missing or
        // zero site_id from the scanner app must not reject the punch.
        $site_id = intval($_POST['site_id'] ?? 0) ?: 1;

        if (!$employee_id || !$scan_time) {
            return ['result' => false, 'message' => 'Missing employee_id or scan_time'];
        }

        // Validate scan_time format
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scan_time);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $scan_time) {
            return ['result' => false, 'message' => 'Invalid scan_time format. Use Y-m-d H:i:s'];
        }

        // Validate employee
        $stmt = $this->db->prepare("SELECT id, firstname, lastname, time_in, time_out FROM employee WHERE id = ? AND status = 1 LIMIT 1");
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        if (!$emp) {
            return ['result' => false, 'message' => 'Employee not found or inactive'];
        }

        $scan_date = $dt->format('Y-m-d');
        $scan_ts   = $dt->getTimestamp();
        $device_id = 0;

        // Resolve employee's current schedule. Shared with dtr_compute_day so the
        // two can never disagree, and it falls back (nearest assignment → Day
        // Shift) instead of returning nothing — a punch made before the schedule
        // was assigned used to skip the overnight re-dating below entirely.
        $schedule = resolve_employee_schedule($this->db, $employee_id, $scan_date);

        // Overnight shift = graveyard flag OR any schedule whose end time wraps past
        // midnight (e.g. Evening 3PM–12AM, Night 11PM–8AM), even when the flag
        // wasn't ticked on the schedule.
        $is_overnight = $schedule && ($schedule['is_graveyard'] || $schedule['end_time'] <= $schedule['start_time']);

        // A scan may belong to the shift that STARTED the previous day. Three cases:
        //  1. Yesterday has an OPEN record and the gap since its last punch still
        //     looks like a single shift (≤16h) → this scan is its time-out. The gap
        //     guard is what keeps a stray punch from an unrelated time of day
        //     (17h+ ago) from merging, while an early clock-in (e.g. 7:45 PM for an
        //     11 PM shift) and a long-OT clock-out both still pair.
        //  2. Yesterday's record is already COMPLETE but this scan is within the
        //     duplicate window of its closing punch → re-date it so the debounce
        //     below swallows the double-tap instead of opening a fresh day.
        //  3. No record yesterday at all, but the scan lands after midnight and
        //     BEFORE (or exactly AT) yesterday's scheduled end → a LATE time-in;
        //     anchor it to yesterday's shift so late/undertime compute against the
        //     right day instead of opening (and mangling) today.
        //
        // This used to run only for overnight ($is_overnight) schedules — but a
        // NON-wrapping evening shift (PM 3–11PM) spills past midnight too when OT
        // runs long: its clock-out landed on the next day, where it was taken as
        // that day's TIME-IN. Every such day then read "complete" with the whole
        // shift as phantom undertime and the OT hours lost. So cases 1–2 now also
        // run for a non-overnight schedule, gated to scans that could plausibly
        // still belong to yesterday's shift: at most 12h (the per-day OT ceiling)
        // past yesterday's scheduled end. Case 3 re-anchors on the scheduled end
        // and stays overnight-only.
        $prev_date = date('Y-m-d', strtotime('-1 day', strtotime($scan_date)));

        // Resolve YESTERDAY's own schedule too, and use IT (not today's) for
        // prev_end and the overnight test below.
        //
        // Both used to come from today's $schedule alone, on the assumption
        // that "today" and "yesterday" run the same recurring shift — true
        // for fixed staff, false the moment a rotating employee's overnight
        // shift is followed by an explicit REST DAY with no shift on file:
        // that resolves today to an unrelated (non-overnight) fixed/period
        // fallback, $is_overnight comes back false, prev_end is computed off
        // the WRONG shift's end time, the spill window never opens, and the
        // punch that should have closed out yesterday's graveyard shift
        // instead opens a stray same-clock-time row on the rest day —
        // leaving yesterday's DTR_details permanently open with no time-out
        // and the hours never paid.
        $prev_schedule     = resolve_employee_schedule($this->db, $employee_id, $prev_date);
        $prev_is_overnight = $prev_schedule && ($prev_schedule['is_graveyard'] || $prev_schedule['end_time'] <= $prev_schedule['start_time']);
        $prev_end = null;
        if ($prev_schedule) {
            $prev_end = strtotime($prev_date . ' ' . $prev_schedule['end_time']);
            if ($prev_schedule['end_time'] <= $prev_schedule['start_time']) {
                $prev_end = strtotime('+1 day', $prev_end); // wraps → ends today
            }
        }
        $spill_window = $is_overnight || $prev_is_overnight
            || ($prev_end !== null && $scan_ts <= $prev_end + 12 * 3600);
        if ($schedule && $spill_window) {
            $chk_prev = $this->db->prepare(
                "SELECT id, logs, is_complete FROM DTR_details
                 WHERE employee_id = ? AND date_time = ? ORDER BY id DESC LIMIT 1"
            );
            $chk_prev->bind_param('is', $employee_id, $prev_date);
            $chk_prev->execute();
            $prevRec = $chk_prev->get_result()->fetch_assoc();
            if ($prevRec) {
                $prevLogs = json_decode($prevRec['logs'], true) ?? [];
                $prevTs   = array_map(function ($l) { return strtotime($l['dateTime']); }, $prevLogs);
                $lastTs   = $prevTs ? max($prevTs) : 0;
                $gap      = $lastTs ? $scan_ts - $lastTs : -1;
                // Non-overnight: only a scan BEFORE today's own shift start may
                // drift back to yesterday — once today's start has passed, the
                // scan is today's (possibly late) time-in, not yesterday's out.
                $before_today_start = $scan_ts < strtotime($scan_date . ' ' . $schedule['start_time']);
                if ($is_overnight || $prev_is_overnight || $before_today_start) {
                    if (!$prevRec['is_complete']) {
                        // "Still looks like one shift" used to mean within 16h of
                        // the LAST punch on file — but that punch is often an early
                        // arrival (2h+ before start), and a 12h graveyard shift plus
                        // a legitimately long OT stretch (still under
                        // DTR_HIGH_OT_HOURS-ish territory) easily clears 16h total
                        // elapsed. A real checkout then missed the merge and opened
                        // a stray next-day row instead. Anchor on the shift's own
                        // SCHEDULED END instead — fixed regardless of how early
                        // they arrived — and allow the same 12h OT ceiling
                        // $spill_window already grants non-overnight shifts above.
                        // Falls back to the old last-punch-relative rule only when
                        // yesterday's shift end could not be resolved at all.
                        $merge_ceiling = $prev_end !== null ? $prev_end + 12 * 3600
                            : ($lastTs ? $lastTs + 16 * 3600 : null);
                        if ($gap > 0 && $merge_ceiling !== null && $scan_ts <= $merge_ceiling) {
                            $scan_date = $prev_date;   // case 1: time-out
                        }
                    } elseif ($gap >= 0 && $gap < 300) {
                        $scan_date = $prev_date;                                      // case 2: double-tap
                    }
                }
            } elseif ($is_overnight || $prev_is_overnight) {
                // Was `<`: an out-punch at EXACTLY the scheduled end (06:00:00 on a
                // 10PM–6AM shift) failed the strict compare and opened a next-day —
                // next-cutoff — orphan row. `<=` keeps it on the shift's own day.
                if ($scan_ts <= $prev_end) $scan_date = $prev_date;                   // case 3: late time-in
            }

            // The re-dated day may fall under a different schedule assignment —
            // resolve it again so hours compute against the shift actually worked.
            if ($scan_date === $prev_date) {
                $resched = resolve_employee_schedule($this->db, $employee_id, $scan_date);
                if ($resched) $schedule = $resched;
                $is_overnight = $schedule && ($schedule['is_graveyard'] || $schedule['end_time'] <= $schedule['start_time']);
            }
        }

        // Detect holiday type from calendar_events
        $day_type = 'regular';
        $hol = $this->db->query("
            SELECT type FROM calendar_events
            WHERE '$scan_date' BETWEEN start_date AND COALESCE(end_date, start_date)
            AND type IN (1,3) ORDER BY type ASC LIMIT 1
        ")->fetch_assoc();
        if ($hol) {
            $day_type = $hol['type'] == 1 ? 'legal_holiday' : 'special_holiday';
        }

        // Resolve employer_id from site
        $stmt2 = $this->db->prepare("SELECT employer_id, site_name FROM sites WHERE id = ? AND status = 1 LIMIT 1");
        $stmt2->bind_param('i', $site_id);
        $stmt2->execute();
        $site_row = $stmt2->get_result()->fetch_assoc();
        if (!$site_row) {
            return ['result' => false, 'message' => 'Site not found or inactive'];
        }
        $employer_id = $site_row['employer_id'];

        // Get a valid admin user id to satisfy the timekeeper_id FK constraint
        $admin_row = $this->db->query("SELECT id FROM users WHERE role = 1 LIMIT 1")->fetch_assoc();
        $admin_id  = $admin_row ? $admin_row['id'] : 1;

        // Resolve the payroll-cutoff period this scan falls into. One DTR batch collects every
        // scan in the period so the admin approves one batch per cutoff. The period type is a
        // global setting (Pay Settings): semi_monthly (default), weekly, or monthly.
        $period_setting = $this->db->query(
            "SELECT setting_value FROM pay_settings WHERE setting_key = 'payroll_period' LIMIT 1"
        )->fetch_assoc();
        $period_code = $period_setting ? (int) $period_setting['setting_value'] : 1;
        $period_type = $period_code === 2 ? 'weekly' : ($period_code === 3 ? 'monthly' : 'semi_monthly');
        $period_ref  = strtotime($scan_date);

        // if ($period_type === 'weekly') {
        //     // Monday–Sunday of the scan's week.
        //     $period_from = date('Y-m-d', strtotime('monday this week', $period_ref));
        //     $period_to   = date('Y-m-d', strtotime('sunday this week', $period_ref));
        // } elseif ($period_type === 'monthly') {
        //     $period_from = date('Y-m-01', $period_ref);
        //     $period_to   = date('Y-m-t',  $period_ref);
        // } else { // semi_monthly: 1st–15th or 16th–end of month
        //     if ((int) date('j', $period_ref) <= 15) {
        //         $period_from = date('Y-m-01', $period_ref);
        //         $period_to   = date('Y-m-15', $period_ref);
        //     } else {
        //         $period_from = date('Y-m-16', $period_ref);
        //         $period_to   = date('Y-m-t',  $period_ref);
        //     }
        // }

        if ((int) date('j', $period_ref) <= 15) {
            $period_from = date('Y-m-01', $period_ref);
            $period_to   = date('Y-m-15', $period_ref);
        } else {
            $period_from = date('Y-m-16', $period_ref);
            $period_to   = date('Y-m-t',  $period_ref);
        }

        $this->db->begin_transaction();
        try {
            // Reuse the open (not-yet-approved) batch for this site + cutoff period, else create it.
            // status IN (0,1) = still collecting/pending; status 2 = approved & locked into payroll,
            // so a late scan after approval starts a fresh batch instead of mutating paid data.
            $stmt3 = $this->db->prepare(
                "SELECT id FROM DTR
                 WHERE date_from = ? AND date_to = ? AND site_id = ? AND device_id = 0
                   AND file = 'biometric' AND status IN (0,1)
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt3->bind_param('ssi', $period_from, $period_to, $site_id);
            $stmt3->execute();
            $dtr_row = $stmt3->get_result()->fetch_assoc();

            if ($dtr_row) {
                $ddtr_id = $dtr_row['id'];
            } else {
                $file = 'biometric';
                // status = 1 => Pending Approval. Biometric attendance is NOT auto-approved;
                // an admin/HR must review it in DTR Review (dtr.php) and approve before payroll picks it up.
                $stmt4 = $this->db->prepare(
                    "INSERT INTO DTR (local_id, date_from, date_to, timekeeper_id, site_id, device_id,
                     file, uploaded_by, employer_id, status) VALUES (0, ?, ?, ?, ?, 0, ?, NULL, ?, 1)"
                );
                $stmt4->bind_param('ssiisi', $period_from, $period_to, $admin_id, $site_id, $file, $employer_id);
                $stmt4->execute();
                if ($this->db->insert_id === 0) {
                    throw new Exception('Failed to create DTR record: ' . $this->db->error);
                }
                $ddtr_id = $this->db->insert_id;
            }

            // Check for an existing DTR_details row for this employee today
            $stmt5 = $this->db->prepare(
                "SELECT id, logs FROM DTR_details WHERE employee_id = ? AND date_time = ? AND ddtr_id = ? LIMIT 1"
            );
            $stmt5->bind_param('isi', $employee_id, $scan_date, $ddtr_id);
            $stmt5->execute();
            $detail = $stmt5->get_result()->fetch_assoc();

            // Manual (admin-authorized) punches are flagged in the log for audit.
            $entry_type = ($_POST['entry_type'] ?? '') === 'manual' ? 'manual' : 'bio';
            $log_entry  = ['dateTime' => $scan_time, 'type' => $entry_type];
            if ($entry_type === 'manual' && !empty($_POST['authorized_by'])) {
                $log_entry['authorized_by'] = substr(trim($_POST['authorized_by']), 0, 80);
            }

            // Shift label stored on the row (shows under Notes in the portal / DTR
            // screens) and echoed in the API response, e.g. "Night Shift · 11:00 PM–8:00 AM".
            $shift_label = $schedule
                ? $schedule['description'] . ' · '
                    . date('g:i A', strtotime($schedule['start_time'])) . '–'
                    . date('g:i A', strtotime($schedule['end_time']))
                : null;
            $shift_note = $shift_label !== null ? substr($shift_label, 0, 100) : null;

            // ── Freeze the shift onto the row ────────────────────────────────
            // The schedule was resolved above to decide overnight pairing; keeping
            // it means every later reader (compute, DTR sheet, payroll) uses the
            // shift this day was actually recorded under, instead of re-resolving
            // it and getting whatever the roster says today. Editing or
            // back-dating an assignment can no longer silently restate closed days.
            $sched_id  = $schedule ? (int) $schedule['id'] : null;
            $sched_dh  = $schedule && isset($schedule['total_hours']) ? (float) $schedule['total_hours'] : null;
            // Boundaries too, not just the id — dtr_compute_day prices late/UT/OT
            // against these, so they must survive a later work_schedules edit.
            $sched_st  = $schedule['start_time'] ?? null;
            $sched_en  = $schedule['end_time'] ?? null;
            $sched_bk  = $schedule && isset($schedule['break_minutes']) ? (int) $schedule['break_minutes'] : null;
            $sched_gv  = $schedule && isset($schedule['is_graveyard']) ? (int) $schedule['is_graveyard'] : null;
            // A published duty-roster day states the rest flag outright; the
            // weekday CSV only describes a FIXED week and cannot answer for a
            // rotating employee whose day off moves. Same precedence as
            // dtr_compute_day, so the stamp written here and any later
            // recompute agree.
            if (isset($schedule['day_is_rest'])) {
                $is_rest = (int) $schedule['day_is_rest'];
            } else {
                $rest_csv  = (string) ($schedule['rest_days'] ?? '');
                $is_rest   = ($rest_csv !== '' && in_array(
                    (int) date('w', strtotime($scan_date)),
                    array_map('intval', explode(',', $rest_csv)),
                    true
                )) ? 1 : 0;
            }

            if (!$detail) {
                // First scan — time-in only, mark incomplete
                $logs = json_encode([$log_entry]);
                $stmt6 = $this->db->prepare(
                    "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, day_type, nsd_hours, is_complete, notes,
                                              schedule_id, day_hours, is_rest_day, sched_start, sched_end, sched_break, sched_graveyard)
                     VALUES (?, ?, ?, 0, ?, 'biometric', ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt6->bind_param('iissssidissii', $ddtr_id, $employee_id, $scan_date, $logs, $day_type, $shift_note,
                                   $sched_id, $sched_dh, $is_rest, $sched_st, $sched_en, $sched_bk, $sched_gv);
                $stmt6->execute();
                $this->db->commit();
                // Notify the employee on their portal bell that a clock-in was captured.
                // Best-effort: a notification hiccup must never fail an already-saved punch.
                try {
                    $this->notifyEmployee(
                        $employee_id,
                        'Time In recorded',
                        'Your clock-in at ' . date('g:i A', $scan_ts) . ' on ' . date('M d, Y', strtotime($scan_date)) . ' was captured.',
                        'ri-login-circle-line',
                        'info',
                        'employee-portal.php?tab=attendance'
                    );
                } catch (\Throwable $e) { /* ignore */
                }
                return [
                    'result'   => true,
                    'message'  => 'Scan recorded',
                    'scan'     => 'in',
                    'day_type' => $day_type,
                    'scan_time' => $scan_time,
                    'dtr_date' => $scan_date,   // shift day the punch was attached to
                    'schedule' => $schedule ? [
                        'description' => $schedule['description'],
                        'start_time'  => $schedule['start_time'],
                        'end_time'    => $schedule['end_time'],
                        'label'       => $shift_label,
                    ] : null,
                ];
            } else {
                // Subsequent scan — recalculate everything, mark complete
                $existing_logs = json_decode($detail['logs'], true) ?? [];

                // Debounce: biometric double-taps / device retries resend the same
                // punch within moments. Appending it would close the day with 0
                // hours (the out becomes identical to the in), so ignore any scan
                // within 5 minutes of a punch already on this record.
                foreach ($existing_logs as $l) {
                    if (abs($scan_ts - strtotime($l['dateTime'])) < 300) {
                        $this->db->commit();
                        return [
                            'result'  => true,
                            'message' => 'Duplicate scan ignored (within 5 minutes of an existing punch)',
                            'scan'    => 'duplicate',
                            'scan_time' => $scan_time,
                        ];
                    }
                }

                $existing_logs[] = $log_entry;

                $timestamps = array_map(function ($l) {
                    return strtotime($l['dateTime']);
                }, $existing_logs);
                sort($timestamps);

                // A tap hours before any plausible arrival for this shift is noise
                // (wrong badge, stray device retry) — pairing it as the day's IN
                // steals the slot from the real punch, zeroes work_hours, and
                // reports the whole shift as undertime while the actual checkout
                // opens a stray new day instead of closing this one. Grace covers
                // ordinary early arrival; kept out of pairing but the raw tap stays
                // in $logs for audit. Must mirror dtr_compute_day's identical
                // filter, or a Recompute would restate this row differently than
                // ingestion originally paired it.
                $plausible = $timestamps;
                if ($schedule) {
                    $grace = strtotime($scan_date . ' ' . $schedule['start_time']) - dtr_early_grace_hours() * 3600;
                    $kept  = array_values(array_filter($timestamps, function ($t) use ($grace) { return $t >= $grace; }));
                    if ($kept) $plausible = $kept;
                }
                $earliest = $plausible[0];
                $latest   = count($plausible) >= 2 ? $plausible[count($plausible) - 1] : null;

                $logs = json_encode($existing_logs);

                if ($latest === null) {
                    // Every other punch on file is noise relative to this shift —
                    // this is still just a time-in, not a complete day. Persist the
                    // logs (nothing is discarded) but leave hours/complete exactly
                    // as an opening punch would.
                    $stmt7a = $this->db->prepare(
                        "UPDATE DTR_details SET logs=?, work_hours=0, overtime=0, late=0, undertime=0,
                         day_type=?, nsd_hours=0, is_complete=0,
                         schedule_id=?, day_hours=?, is_rest_day=?,
                         sched_start=?, sched_end=?, sched_break=?, sched_graveyard=? WHERE id=?"
                    );
                    $stmt7a->bind_param(
                        'ssidissiii',
                        $logs,
                        $day_type,
                        $sched_id,
                        $sched_dh,
                        $is_rest,
                        $sched_st,
                        $sched_en,
                        $sched_bk,
                        $sched_gv,
                        $detail['id']
                    );
                    $stmt7a->execute();
                    $this->db->commit();
                    try {
                        $this->notifyEmployee(
                            $employee_id,
                            'Time In recorded',
                            'Your clock-in at ' . date('g:i A', $scan_ts) . ' on ' . date('M d, Y', strtotime($scan_date)) . ' was captured.',
                            'ri-login-circle-line',
                            'info',
                            'employee-portal.php?tab=attendance'
                        );
                    } catch (\Throwable $e) { /* ignore */
                    }
                    return [
                        'result'   => true,
                        'message'  => 'Scan recorded',
                        'scan'     => 'in',
                        'day_type' => $day_type,
                        'scan_time' => $scan_time,
                        'dtr_date' => $scan_date,
                        'schedule' => $schedule ? [
                            'description' => $schedule['description'],
                            'start_time'  => $schedule['start_time'],
                            'end_time'    => $schedule['end_time'],
                            'label'       => $shift_label,
                        ] : null,
                    ];
                }

                $raw_hours  = ($latest - $earliest) / 3600;
                $break_hrs  = ($schedule['break_minutes'] ?? 60) / 60;

                // Schedule-based: late, undertime, overtime.
                // Anchor the shift to the DTR row's day ($scan_date), NOT the first
                // punch's calendar date — on an overnight shift a late time-in lands
                // after midnight, and anchoring to it would shift the whole schedule
                // one day forward (zero late, ~22h undertime).
                $late = $undertime = $overtime = 0;
                $late_raw = 0; $late_rule = 'none';
                if ($schedule) {
                    $sched_start = strtotime($scan_date . ' ' . $schedule['start_time']);
                    $sched_end   = strtotime($scan_date . ' ' . $schedule['end_time']);
                    if ($is_overnight) $sched_end = strtotime('+1 day', $sched_end);
                    // Same shared paid-time maths as dtr_compute_day
                    // (dtr_shift_figures, db_connect.php): break overlap
                    // excluded, late priced by the pay_settings grace /
                    // bracket / half-day rules, work capped so worked + late +
                    // UT == the day. Anything computed here by hand drifted
                    // from the recompute path sooner or later.
                    $fig        = dtr_shift_figures($earliest, $latest, $sched_start, $sched_end, $schedule);
                    $late       = $fig['late'];
                    $undertime  = $fig['undertime'];
                    $overtime   = $fig['overtime'];
                    $work_hours = $fig['work_hours'];
                    $late_raw   = $fig['late_raw'];
                    $late_rule  = $fig['late_rule'];
                } else {
                    $work_hours = max(0, $raw_hours - $break_hrs);
                    $overtime   = round(max(0, $work_hours - 8), 2);
                    $work_hours = round(min(8, $work_hours), 2);
                }

                // NSD: hours worked inside 22:00–06:00 — one contiguous 8-hour
                // window crossing midnight, swept across the row's neighbouring
                // days. Mirrors dtr_compute_day exactly; the old per-day window
                // pair dropped the 23:59:59→00:00 second and missed the previous
                // evening's window on a re-dated overnight row.
                $nsd_hours = 0;
                for ($k = -1; $k <= 1; $k++) {
                    $w_start = strtotime(date('Y-m-d', strtotime("$scan_date $k day")) . ' 22:00:00');
                    $w_end   = strtotime('+8 hours', $w_start);   // 22:00 → 06:00 next day
                    $nsd_hours += max(0, min($latest, $w_end) - max($earliest, $w_start)) / 3600;
                }
                $nsd_hours = round($nsd_hours, 2);

                // Re-stamp on the closing punch too: a scan re-dated onto the
                // previous day resolves its schedule again, so the row must end up
                // with the shift the completed day was actually computed against.
                $stmt7 = $this->db->prepare(
                    "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, late=?, undertime=?,
                     day_type=?, nsd_hours=?, is_complete=1,
                     schedule_id=?, day_hours=?, is_rest_day=?,
                     sched_start=?, sched_end=?, sched_break=?, sched_graveyard=? WHERE id=?"
                );
                $stmt7->bind_param(
                    'sddddsdidissiii',
                    $logs,
                    $work_hours,
                    $overtime,
                    $late,
                    $undertime,
                    $day_type,
                    $nsd_hours,
                    $sched_id,
                    $sched_dh,
                    $is_rest,
                    $sched_st,
                    $sched_en,
                    $sched_bk,
                    $sched_gv,
                    $detail['id']
                );
                $stmt7->execute();
                $this->db->commit();

                // Notify the employee: day complete, with the computed hours summary.
                // Best-effort: a notification hiccup must never fail an already-saved punch.
                try {
                    $ot_txt   = $overtime  > 0 ? ', ' . round($overtime, 2) . ' hr OT'         : '';
                    // Say what was CHARGED, and why, when a rule reshaped it —
                    // "16 min late" alone would not explain a 1-hour deduction.
                    if ($late_rule === 'half_day')     $late_txt = ', HALF DAY (arrived ' . (int) round($late_raw) . ' min late)';
                    elseif ($late_rule === 'bracket')  $late_txt = ', ' . (int) round($late_raw) . ' min late → ' . rtrim(rtrim(number_format($late, 2), '0'), '.') . ' hr charged';
                    elseif ($late > 0)                 $late_txt = ', ' . (int) round($late * 60) . ' min late';
                    else                               $late_txt = '';
                    $this->notifyEmployee(
                        $employee_id,
                        'Time Out recorded',
                        'Attendance for ' . date('M d, Y', strtotime($scan_date)) . ' complete: '
                            . date('g:i A', $earliest) . ' – ' . date('g:i A', $latest) . ' · '
                            . round($work_hours, 2) . ' hrs worked' . $ot_txt . $late_txt . '.',
                        'ri-logout-circle-line',
                        'success',
                        'employee-portal.php?tab=attendance'
                    );
                    // The day is complete, so its ceiling is finally knowable —
                    // this is the moment to tell them it needs filing.
                    $this->notifyUnfiledHours($employee_id, $scan_date);
                } catch (\Throwable $e) { /* ignore */
                }

                return [
                    'result'            => true,
                    'message'           => 'Scan recorded',
                    'scan'              => 'out',
                    'day_type'          => $day_type,
                    'dtr_date'          => $scan_date,   // shift day the punch was attached to
                    'time_in'           => date('Y-m-d H:i:s', $earliest),
                    'time_out'          => date('Y-m-d H:i:s', $latest),
                    'work_hours'        => round($work_hours, 2),
                    'overtime_hours'    => round($overtime, 2),
                    'late_minutes'      => (int) round($late * 60),      // minutes CHARGED
                    'late_raw_minutes'  => (int) round($late_raw),       // paid minutes actually late
                    'late_rule'         => $late_rule,                   // none|exact|bracket|half_day
                    'undertime_minutes' => (int) round($undertime * 60),
                    'nsd_hours'         => $nsd_hours,
                    'schedule' => $schedule ? [
                        'description' => $schedule['description'],
                        'start_time'  => $schedule['start_time'],
                        'end_time'    => $schedule['end_time'],
                        'label'       => $shift_label,
                    ] : null,
                ];
            }
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    /* ── Biometric scanner app: login ─────────────────────────────────────────
     * ADMIN-ONLY (role = 1). Validates the account and hands back the
     * biometric API key as the access token for subsequent scanner calls. */
    function biometric_login()
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            return ['result' => false, 'message' => 'Username and password are required'];
        }

        $stmt = $this->db->prepare("SELECT id, name, site_id, password, role FROM users WHERE username = ? AND status = 1 LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            return ['result' => false, 'message' => 'Invalid username or password'];
        }

        // Only administrators (1) and timekeepers (5) may operate the scanner
        // device — the timekeeper role exists precisely for this.
        if (!in_array((int) $user['role'], [1, ROLE_TIMEKEEPER], true)) {
            return ['result' => false, 'message' => 'Access denied — administrator or timekeeper account required'];
        }

        // Scanner posts attendance against a site. Resolve it in order of how
        // specific the link is:
        //   1. users.site_id            — explicitly set on the account
        //   2. sites.timekeeper_id      — the site this timekeeper was assigned
        //                                 to on the Biometric Sites screen
        //   3. first active site        — last-resort fallback
        $site_id = (int) ($user['site_id'] ?? 0);
        if (!$site_id) {
            $own = $this->db->prepare("SELECT id FROM sites WHERE timekeeper_id = ? AND status = 1 ORDER BY id ASC LIMIT 1");
            $own->bind_param('i', $user['id']);
            $own->execute();
            $row = $own->get_result()->fetch_assoc();
            $site_id = $row ? (int) $row['id'] : 0;
        }
        if (!$site_id) {
            $site = $this->db->query("SELECT id FROM sites WHERE status = 1 ORDER BY id ASC LIMIT 1")->fetch_assoc();
            $site_id = $site ? (int) $site['id'] : 0;
        }

        $parts      = preg_split('/\s+/', trim($user['name']), 2);
        $first_name = $parts[0] ?? $user['name'];
        $last_name  = $parts[1] ?? '';

        return [
            'result'       => true,
            'message'      => 'Login successful',
            'access_token' => BIOMETRIC_API_KEY,
            'user_id'      => (int) $user['id'],
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'site_id'      => $site_id,
            'role'         => (int) $user['role'],
        ];
    }

    /* ── Biometric scanner app: active employee list ────────────────────────── */
    function get_biometric_employees()
    {
        $res = $this->db->query("
            SELECT e.id, e.employee_no, e.firstname, e.lastname, e.status,
                   COALESCE(d.name, '') AS department,
                   COALESCE(p.name, '') AS position,
                   COALESCE(c.clasification, '') AS classification,
                   COALESCE(fp.fp_count, 0) AS fingerprint_count,
                   COALESCE(fp.fingers, '') AS fingers
            FROM employee e
            LEFT JOIN department    d ON d.id = e.department_id
            LEFT JOIN position      p ON p.id = e.position_id
            LEFT JOIN clasification c ON c.id = e.clasification_id
            LEFT JOIN (
                SELECT employee_id,
                       COUNT(*) AS fp_count,
                       GROUP_CONCAT(finger_index ORDER BY finger_index SEPARATOR ', ') AS fingers
                FROM employee_fingerprints
                GROUP BY employee_id
            ) fp ON fp.employee_id = e.id
            WHERE e.status = 1
            ORDER BY e.lastname, e.firstname
        ");

        // Today's and yesterday's resolved shift per employee. The scanner uses
        // this ONLY as a tie-breaker when one scan verifies against two people
        // almost equally: the one who is actually on shift right now wins;
        // if both/neither are, the scan is still rejected. Yesterday is sent
        // so a night shift that started yesterday still counts after midnight.
        // Compact: {s: "HH:MM:SS", e: "HH:MM:SS", g: 0|1 graveyard, r: 0|1 rest}.
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $shiftOf = function ($employee_id, $date) {
            $sc = function_exists('resolve_employee_schedule') ? resolve_employee_schedule($this->db, (int) $employee_id, $date) : null;
            if (!$sc) return null;
            $rest = isset($sc['day_is_rest']) ? (int) $sc['day_is_rest'] : 0;
            if (!$rest && !empty($sc['rest_days'])) {
                // Fixed-week rest days CSV, e.g. "Sun,Sat" or "0,6" — match either style.
                $dow = date('D', strtotime($date)); $dowN = date('w', strtotime($date));
                foreach (array_map('trim', explode(',', $sc['rest_days'])) as $rd) {
                    if ($rd !== '' && (strcasecmp($rd, $dow) === 0 || $rd === (string) $dowN)) { $rest = 1; break; }
                }
            }
            return ['s' => substr((string) $sc['start_time'], 0, 8), 'e' => substr((string) $sc['end_time'], 0, 8),
                    'g' => (int) ($sc['is_graveyard'] ?? 0), 'r' => $rest];
        };

        $employees = [];
        while ($row = $res->fetch_assoc()) {
            $employees[] = [
                'id'             => (int) $row['id'],
                'employee_no'    => $row['employee_no'],
                'first_name'     => $row['firstname'],
                'last_name'      => $row['lastname'],
                'department'     => $row['department'],
                'position'       => $row['position'],
                'classification' => $row['classification'],
                'is_active'      => (int) $row['status'],
                'fingerprint_count' => (int) $row['fingerprint_count'],
                'fingers'           => $row['fingers'],
                'shift_today'       => $shiftOf($row['id'], $today),
                'shift_yesterday'   => $shiftOf($row['id'], $yesterday),
            ];
        }

        $enrolled = count(array_filter($employees, fn($e) => $e['fingerprint_count'] > 0));

        return [
            'result'    => true,
            'employees' => $employees,
            'total'     => count($employees),
            'enrolled'  => $enrolled,
        ];
    }

    /* ── Biometric scanner app: all stored fingerprint templates ────────────── */
    function get_biometric_fingerprints()
    {
        $res = $this->db->query("
            SELECT f.employee_id, f.finger_index, f.template
            FROM employee_fingerprints f
            INNER JOIN employee e ON e.id = f.employee_id AND e.status = 1
            ORDER BY f.employee_id, f.finger_index
        ");

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'employee_id'  => (int) $row['employee_id'],
                'finger_index' => $row['finger_index'],
                'template'     => $row['template'],
            ];
        }

        return ['result' => true, 'data' => $data, 'total' => count($data)];
    }

    /* ── Biometric scanner app: today's attendance summary ──────────────────── */
    /* ── Biometric scanner app: manual attendance (admin-authorized) ─────────
     * Fallback for a finger that cannot scan (bandage, injury). The admin's
     * credentials are re-verified here and the punch goes through the normal
     * biometric save flow flagged type=manual + authorized_by in the log. */
    /**
     * Scanner → server: a live scan verified against MORE THAN ONE employee
     * (or during an audit sweep in Check Fingerprint). Stores the ranked candidate list so an
     * admin can see exactly which fingers look alike and re-enroll them.
     * Never affects attendance — purely an audit trail. Auth: Bearer token
     * (same as save-attendance).
     */
    function report_biometric_similarity()
    {
        $scan_time  = trim($_POST['scan_time'] ?? '');
        $matched    = intval($_POST['matched_employee_id'] ?? 0) ?: null;
        $decision   = trim($_POST['decision'] ?? 'saved');
        $candidates = (string) ($_POST['candidates'] ?? '');
        $device     = trim($_POST['device'] ?? '');
        $operator   = intval($_POST['operator_user_id'] ?? 0) ?: null;

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scan_time);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $scan_time) {
            return ['result' => false, 'message' => 'Invalid scan_time format. Use Y-m-d H:i:s'];
        }
        if (!in_array($decision, ['saved', 'ambiguous', 'debug', 'audit', 'nomatch'], true)) {
            $decision = 'saved';
        }
        $list = json_decode($candidates, true);
        if (!is_array($list)) {
            return ['result' => false, 'message' => 'candidates must be a JSON array'];
        }
        // Keep the payload bounded — the scanner sends the top few only, but
        // never trust a client with an unbounded TEXT column.
        $list = array_slice($list, 0, 25);
        $verified = 0;
        foreach ($list as $c) {
            if (!empty($c['verified'])) {
                $verified++;
            }
        }
        $json = json_encode($list, JSON_UNESCAPED_UNICODE);
        if ($device === '') {
            $device = null;
        }

        $this->db->query("CREATE TABLE IF NOT EXISTS biometric_similarity_reports (
            id INT(11) NOT NULL AUTO_INCREMENT,
            scan_time DATETIME NOT NULL,
            matched_employee_id INT(11) DEFAULT NULL,
            decision VARCHAR(20) NOT NULL DEFAULT 'saved',
            candidate_count INT(11) NOT NULL DEFAULT 0,
            candidates TEXT NOT NULL,
            device VARCHAR(100) DEFAULT NULL,
            operator_user_id INT(11) DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            reviewed_by INT(11) DEFAULT NULL,
            review_note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_matched (matched_employee_id), KEY idx_scan_time (scan_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmt = $this->db->prepare(
            "INSERT INTO biometric_similarity_reports
                (scan_time, matched_employee_id, decision, candidate_count, candidates, device, operator_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return ['result' => false, 'message' => 'DB error: ' . $this->db->error];
        }
        $stmt->bind_param('sisissi', $scan_time, $matched, $decision, $verified, $json, $device, $operator);
        if (!$stmt->execute()) {
            return ['result' => false, 'message' => 'DB error: ' . $stmt->error];
        }
        return ['result' => true, 'id' => $stmt->insert_id, 'candidate_count' => $verified];
    }

    function manual_biometric_attendance()
    {
        $employee_no = trim($_POST['employee_no'] ?? '');
        $username    = trim($_POST['admin_username'] ?? '');
        $password    = (string) ($_POST['admin_password'] ?? '');

        if ($employee_no === '' || $username === '' || $password === '') {
            return ['result' => false, 'message' => 'Missing employee_no, admin_username or admin_password'];
        }

        // Re-verify the authorizing admin (role = 1) — same rule as scanner login.
        $stmt = $this->db->prepare("SELECT id, name, password, role FROM users WHERE username = ? AND status = 1 LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            return ['result' => false, 'message' => 'Invalid admin username or password'];
        }
        if ((int) $user['role'] !== 1) {
            return ['result' => false, 'message' => 'Access denied — administrator account required'];
        }

        $stmt2 = $this->db->prepare("
            SELECT id, firstname, lastname FROM employee
            WHERE (employee_no = ? OR employee_code = ?) AND status = 1
            LIMIT 1
        ");
        $stmt2->bind_param('ss', $employee_no, $employee_no);
        $stmt2->execute();
        $emp = $stmt2->get_result()->fetch_assoc();
        if (!$emp) {
            return ['result' => false, 'message' => 'No active employee with number ' . $employee_no];
        }

        // Reuse the full biometric save flow (schedule resolution, overnight
        // shifts, duplicate window) with the manual-entry flag set.
        $_POST['employee_id']   = (string) $emp['id'];
        $_POST['entry_type']    = 'manual';
        $_POST['authorized_by'] = trim($user['name']) . ' (' . $username . ')';

        $result = $this->save_biometric_attendance();
        if (!empty($result['result'])) {
            $result['employee'] = trim($emp['firstname'] . ' ' . $emp['lastname']);
            $result['manual']   = true;
        }
        return $result;
    }

    function get_biometric_attendance_summary()
    {
        $today = date('Y-m-d');

        $total = $this->db->query("SELECT COUNT(*) AS c FROM employee WHERE status = 1")
            ->fetch_assoc()['c'] ?? 0;

        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT employee_id) AS present,
                   COALESCE(SUM(is_complete = 1), 0) AS completed,
                   COALESCE(SUM(is_complete = 0), 0) AS time_in_only,
                   COALESCE(SUM(late > 0), 0) AS late
            FROM DTR_details
            WHERE date_time = ?
        ");
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];

        return [
            'result'          => true,
            'date'            => $today,
            'total_employees' => (int) $total,
            'present'         => (int) ($row['present'] ?? 0),
            'time_in_only'    => (int) ($row['time_in_only'] ?? 0),
            'completed'       => (int) ($row['completed'] ?? 0),
            'late'            => (int) ($row['late'] ?? 0),
        ];
    }

    /* ── Biometric scanner app: save (upsert) a fingerprint template ──────────
     * finger_index uses the DigitalPersona names, e.g. RIGHT_INDEX, LEFT_THUMB. */
    function save_biometric_fingerprint()
    {
        $employee_id  = intval($_POST['employee_id'] ?? 0);
        $finger_index = strtoupper(trim($_POST['finger_index'] ?? ''));
        $template     = trim($_POST['template'] ?? '');

        if (!$employee_id || $finger_index === '' || $template === '') {
            return ['result' => false, 'message' => 'Missing employee_id, finger_index or template'];
        }

        $valid_fingers = [
            'RIGHT_THUMB', 'RIGHT_INDEX', 'RIGHT_MIDDLE', 'RIGHT_RING', 'RIGHT_PINKY', 'RIGHT_LITTLE',
            'LEFT_THUMB', 'LEFT_INDEX', 'LEFT_MIDDLE', 'LEFT_RING', 'LEFT_PINKY', 'LEFT_LITTLE',
        ];
        if (!in_array($finger_index, $valid_fingers, true)) {
            return ['result' => false, 'message' => 'Invalid finger_index: ' . $finger_index];
        }

        // Template must be valid base64 (the scanner sends a serialized DPFP template).
        if (base64_decode($template, true) === false) {
            return ['result' => false, 'message' => 'Template is not valid base64'];
        }

        $stmt = $this->db->prepare("SELECT id FROM employee WHERE id = ? AND status = 1 LIMIT 1");
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return ['result' => false, 'message' => 'Employee not found or inactive'];
        }

        /* Duplicate guard: the exact same template blob under a different
         * employee means a data mix-up (re-sent enrollment, copy-paste).
         * Biometric similarity between different enrollments of one finger is
         * checked by the scanner app — the server can only compare bytes. */
        $dup_stmt = $this->db->prepare("
            SELECT f.employee_id, CONCAT(e.firstname, ' ', e.lastname) AS name
            FROM employee_fingerprints f
            INNER JOIN employee e ON e.id = f.employee_id
            WHERE f.template = ? AND f.employee_id != ?
            LIMIT 1
        ");
        $dup_stmt->bind_param('si', $template, $employee_id);
        $dup_stmt->execute();
        if ($dup = $dup_stmt->get_result()->fetch_assoc()) {
            return [
                'result'  => false,
                'message' => 'This fingerprint is already registered to ' . trim($dup['name'])
                           . ' (employee #' . $dup['employee_id'] . ')',
            ];
        }

        $stmt2 = $this->db->prepare("
            INSERT INTO employee_fingerprints (employee_id, finger_index, template)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE template = VALUES(template), updated_at = NOW()
        ");
        $stmt2->bind_param('iss', $employee_id, $finger_index, $template);
        try {
            $stmt2->execute();
        } catch (Exception $e) {
            return ['result' => false, 'message' => 'Failed to save fingerprint: ' . $e->getMessage()];
        }

        return [
            'result'       => true,
            'message'      => 'Fingerprint saved',
            'employee_id'  => $employee_id,
            'finger_index' => $finger_index,
        ];
    }

    function updateContributionAmount($contricutions, $dd_id, $value, $id)
    {
        foreach ($contricutions as &$contribution) {
            if ($contribution[$id] === $dd_id) {
                $contribution['amount'] = $value;
                return $contricutions; // Return updated array immediately
            }
        }
        return $contricutions; // Return original array if no match is found
    }

    // Reviewer color mark per payroll row: 0=none, 1=ok(green), 2=issue(orange), 3=reviewing(blue)
    function set_payroll_item_review()
    {
        $id = (int) ($_POST['id'] ?? 0);
        $status = (int) ($_POST['review_status'] ?? 0);
        $comment = trim($_POST['review_comment'] ?? '');
        if ($id <= 0 || !in_array($status, [0, 1, 2, 3])) {
            return 0;
        }
        $stmt = $this->db->prepare("UPDATE payroll_items SET review_status = ?, review_comment = ? WHERE id = ?");
        $stmt->bind_param("isi", $status, $comment, $id);
        return $stmt->execute() ? 1 : 0;
    }

    /**
     * May this payroll_item be written to right now?
     *
     * Until now the ONLY thing stopping an edit was whether the page happened to
     * render an <input>, which meant a browser tab left open from before a batch
     * was sent for review (or locked) could still silently save over figures
     * employees were in the middle of confirming. The server decides now.
     *
     * Open payroll (status 1) → editable.
     * Out for review (3)      → editable ONLY for a row an admin explicitly
     *                           unlocked (see unlock_payroll_item).
     * Locked (2) / New (0)    → never.
     *
     * Returns null when the write is allowed, or an error array to return as-is.
     */
    private function payroll_item_write_block($itemId)
    {
        $itemId = (int) $itemId;
        if (!$itemId) return ['result' => false, 'message' => 'Missing payroll item.'];

        // unlocked_at ships in 2026_07_payroll_item_unlock.sql — older databases
        // simply have no per-row unlock, so treat every row as locked.
        $hasUnlock = $this->db->query("SHOW COLUMNS FROM payroll_items LIKE 'unlocked_at'");
        $unlockCol = ($hasUnlock && $hasUnlock->num_rows) ? 'i.unlocked_at' : 'NULL AS unlocked_at';

        $row = $this->db->query("SELECT p.status, $unlockCol
                FROM payroll_items i
                INNER JOIN payroll p ON p.id = i.payroll_id
                WHERE i.id = $itemId")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Payroll item not found.'];

        $status = (int) $row['status'];
        if ($status === 1) return null;
        if ($status === 3 && !empty($row['unlocked_at'])) return null;

        return ['result' => false, 'message' => $status === 2
            ? 'This payroll is locked — reopen it before editing.'
            : 'This payroll is out for employee review. Unlock the employee first to edit their figures.',
            'reload' => true];
    }

    /**
     * Add a named one-off line to ONE employee's payslip — "Uniform ₱500" as a
     * deduction, "Transport ₱300" as an allowance — without recalculating the
     * batch (which would rebuild payroll_items and discard manual corrections).
     *
     * Goes through the same payroll_item_write_block() gate as any other edit,
     * so an in-review batch still requires that employee to be unlocked first.
     */
    function save_payroll_item_extra()
    {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($block = $this->payroll_item_write_block($itemId)) return $block;

        $extraId = (int)($_POST['id'] ?? 0);          // >0 edits an existing line
        $kind    = (int)($_POST['kind'] ?? 1) === 2 ? 2 : 1;   // 2 allowance, else deduction
        $label   = trim((string)($_POST['label'] ?? ''));
        $amount  = round((float)($_POST['amount'] ?? 0), 2);

        if ($label === '')  return ['result' => false, 'message' => 'A label is required.'];
        if (mb_strlen($label) > 120) $label = mb_substr($label, 0, 120);
        if ($amount <= 0)   return ['result' => false, 'message' => 'Enter an amount greater than zero.'];

        $row = $this->db->query("SELECT payroll_id, employee_id FROM payroll_items WHERE id = $itemId")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Payroll item not found.'];

        $uid = (int)($_SESSION['login_id'] ?? 0) ?: null;
        if ($extraId) {
            $st = $this->db->prepare("UPDATE payroll_item_extras SET kind = ?, label = ?, amount = ?
                                      WHERE id = ? AND payroll_item_id = ?");
            $st->bind_param('isdii', $kind, $label, $amount, $extraId, $itemId);
        } else {
            $st = $this->db->prepare("INSERT INTO payroll_item_extras
                    (payroll_item_id, payroll_id, employee_id, kind, label, amount, created_by)
                    VALUES (?,?,?,?,?,?,?)");
            $st->bind_param('iiiisdi', $itemId, $row['payroll_id'], $row['employee_id'], $kind, $label, $amount, $uid);
        }
        if (!$st->execute()) return ['result' => false, 'message' => $st->error];
        if (!$extraId) $extraId = $this->db->insert_id;

        $this->save_payroll_history((int)$row['payroll_id'], 5,
            ($kind === 2 ? 'Added allowance "' : 'Added deduction "') . $label . '" ' . number_format($amount, 2) . ' —');

        // The stored net is what the portal and payslips read — keep it true.
        $this->resync_item_net($itemId);
        // Changing what an employee is paid invalidates any sign-off they gave.
        $this->void_signoff_for_item($itemId);

        return ['result' => true, 'message' => 'Item saved.', 'id' => $extraId,
                'extras' => $this->payroll_item_extras($itemId)];
    }

    /** Remove one extra line from an employee's payslip. */
    function delete_payroll_item_extra()
    {
        $extraId = (int)($_POST['id'] ?? 0);
        if (!$extraId) return ['result' => false, 'message' => 'Missing item.'];

        $row = $this->db->query("SELECT payroll_item_id, payroll_id, kind, label, amount
                                 FROM payroll_item_extras WHERE id = $extraId")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Item not found.'];
        if ($block = $this->payroll_item_write_block($row['payroll_item_id'])) return $block;

        if (!$this->db->query("DELETE FROM payroll_item_extras WHERE id = $extraId")) {
            return ['result' => false, 'message' => $this->db->error];
        }
        $this->save_payroll_history((int)$row['payroll_id'], 5,
            'Removed ' . ((int)$row['kind'] === 2 ? 'allowance' : 'deduction') . ' "' . $row['label'] . '" —');
        $this->resync_item_net($row['payroll_item_id']);
        $this->void_signoff_for_item($row['payroll_item_id']);

        return ['result' => true, 'message' => 'Item removed.',
                'extras' => $this->payroll_item_extras($row['payroll_item_id'])];
    }

    /**
     * Re-attach a payroll's one-off items after its payroll_items rows were
     * rebuilt (recalculation DELETEs and re-INSERTs them, so every stored
     * payroll_item_id is stale). Extras carry payroll_id + employee_id exactly
     * so they can be found again.
     *
     * An employee who dropped out of the recalculated batch has their extras
     * removed rather than left dangling — otherwise the lines would silently
     * reappear the next time that employee turned up in the payroll.
     * Finally each touched row's stored net is recomputed to include them.
     */
    private function relink_payroll_extras($payrollId)
    {
        $payrollId = (int) $payrollId;
        $has = $this->db->query("SHOW TABLES LIKE 'payroll_item_extras'");
        if (!$has || !$has->num_rows) return;

        $any = $this->db->query("SELECT COUNT(*) AS c FROM payroll_item_extras
                                 WHERE payroll_id = $payrollId")->fetch_assoc();
        if (!$any || !(int)$any['c']) return;

        // One target row per employee (an employee can hold several items when a
        // payroll spans sites — the lowest id is the stable, repeatable choice).
        $this->db->query("
            UPDATE payroll_item_extras x
            INNER JOIN (
                SELECT employee_id, MIN(id) AS item_id
                FROM payroll_items WHERE payroll_id = $payrollId
                GROUP BY employee_id
            ) pick ON pick.employee_id = x.employee_id
            SET x.payroll_item_id = pick.item_id
            WHERE x.payroll_id = $payrollId");

        // Employees no longer in the batch: drop their now-meaningless extras.
        $this->db->query("
            DELETE x FROM payroll_item_extras x
            LEFT JOIN payroll_items i ON i.id = x.payroll_item_id AND i.payroll_id = x.payroll_id
            WHERE x.payroll_id = $payrollId AND i.id IS NULL");

        // Stored net must include the re-attached items.
        $ids = $this->db->query("SELECT DISTINCT payroll_item_id FROM payroll_item_extras
                                 WHERE payroll_id = $payrollId");
        if ($ids) while ($r = $ids->fetch_assoc()) $this->resync_item_net((int)$r['payroll_item_id']);
    }

    /**
     * Re-persist payroll_items.net for one row.
     *
     * The employee portal, payslip PDFs and reports read the STORED net rather
     * than recomputing it, so an extra that only lived in payroll_item_extras
     * would be invisible to all of them. Recomputed from the row's own figures
     * plus its extras — never from a delta, so repeated saves cannot drift.
     */
    private function resync_item_net($itemId)
    {
        $itemId = (int)$itemId;
        $row = $this->db->query("SELECT pi.*, pay.settings, pay.type AS payroll_type
                FROM payroll_items pi INNER JOIN payroll pay ON pay.id = pi.payroll_id
                WHERE pi.id = $itemId")->fetch_assoc();
        if (!$row) return;

        // ONE formula for every screen, sheet, export and stored figure —
        // payroll_earnings() in db_connect.php. This method used to keep its own
        // copy, and the copy had drifted: it paid rest-day duty as a whole extra
        // day for DAILY staff (the shared formula pays the 30% premium, since the
        // day itself is already inside `present`) and it ignored paid_leave. So
        // the net written here could disagree with the gross rendered on the very
        // same row.
        $gross = payroll_earnings($row)['gross'];

        $ded = 0.0;
        foreach (['contributions', 'deductions', 'loans'] as $col) {
            foreach ((json_decode($row[$col] ?? '', true) ?: []) as $c) $ded += (float)($c['amount'] ?? 0);
        }
        $ded += (float)$row['sss_fund'] + (float)$row['jei_advances']
              + (float)$row['jcc_advances'] + (float)$row['tax'] + (float)$row['other_deduction'];

        $ref = 0.0;
        foreach ((json_decode($row['refunds'] ?? '', true) ?: []) as $r) $ref += (float)($r['amount'] ?? 0);

        $xAdd = $xLess = 0.0;
        foreach ($this->payroll_item_extras($itemId) as $x) {
            if ($x['kind'] === 2) $xAdd += $x['amt']; else $xLess += $x['amt'];
        }

        $gross += $xAdd;               // allowance extras belong in gross
        $net = $gross - $ded - $xLess + $ref + (float)($row['adjustment'] ?? 0);
        $st = $this->db->prepare("UPDATE payroll_items SET net = ? WHERE id = ?");
        $st->bind_param('di', $net, $itemId);
        $st->execute();
    }

    /** Every extra line on one payroll item, oldest first. */
    private function payroll_item_extras($itemId)
    {
        $itemId = (int)$itemId;
        $out = [];
        $q = $this->db->query("SELECT id, kind, label, amount FROM payroll_item_extras
                               WHERE payroll_item_id = $itemId ORDER BY id ASC");
        if ($q) while ($r = $q->fetch_assoc()) {
            $out[] = ['id' => (int)$r['id'], 'kind' => (int)$r['kind'],
                      'label' => $r['label'], 'amt' => (float)$r['amount']];
        }
        return $out;
    }

    /**
     * Remittance breakdown — totals per contribution / deduction / loan / refund
     * type for one payroll, recomputed from payroll_items on every call.
     *
     * The modal used to be PHP-rendered once at page load, so after saving an
     * edit it still showed the figures from before the change (the workbench
     * updates in place and never reloads). Fetching it fresh each time the modal
     * opens keeps it honest no matter what moved the numbers — a save here, an
     * unlocked correction, or another admin working the same batch.
     */
    function remittance_breakdown()
    {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing payroll id.'];

        $payroll = $this->db->query("SELECT settings FROM payroll WHERE id = $id")->fetch_assoc();
        if (!$payroll) return ['result' => false, 'message' => 'Payroll not found.'];

        // settings lists every configured type; type 4 is refunds, the rest are
        // deductions (1 contribution, 2 deduction, 3 loan).
        $settings = json_decode($payroll['settings'] ?? '', true) ?: [];

        // key "type-id" => ['type','id','total','employees']
        $remit = [];
        $add = function ($type, $ddId, $amount) use (&$remit) {
            $key = $type . '-' . $ddId;
            if (!isset($remit[$key])) {
                $remit[$key] = ['type' => (int)$type, 'id' => (int)$ddId, 'total' => 0.0, 'employees' => 0];
            }
            $remit[$key]['total'] += (float)$amount;
            if ((float)$amount > 0) $remit[$key]['employees']++;
        };

        // Which JSON column and which id key each configured type reads from —
        // mirrors the table loops in payroll_calculations.php exactly.
        $srcFor = [
            1 => ['contributions', 'contribution_id'],
            2 => ['deductions',    'deduction_id'],
            3 => ['loans',         'deduction_id'],
            4 => ['refunds',       'refund_id'],
        ];

        $rows = $this->db->query("SELECT contributions, deductions, loans, refunds
                                  FROM payroll_items WHERE payroll_id = $id");
        if ($rows) while ($row = $rows->fetch_assoc()) {
            $decoded = [
                'contributions' => json_decode($row['contributions'] ?? '', true) ?: [],
                'deductions'    => json_decode($row['deductions'] ?? '', true) ?: [],
                'loans'         => json_decode($row['loans'] ?? '', true) ?: [],
                'refunds'       => json_decode($row['refunds'] ?? '', true) ?: [],
            ];
            foreach ($settings as $cfg) {
                $type = (int)($cfg['type'] ?? 0);
                if (!isset($srcFor[$type])) continue;
                [$col, $idKey] = $srcFor[$type];
                $amount = 0;
                foreach ($decoded[$col] as $entry) {
                    if (($entry[$idKey] ?? null) == $cfg['id']) $amount = $entry['amount'] ?? 0;
                }
                $add($type, $cfg['id'], $amount);
            }
        }

        // Group for display, resolving each type's name from its source table.
        $labels = [
            1 => ['label' => 'Contributions', 'icon' => 'ri-hand-coin-line'],
            2 => ['label' => 'Deductions',    'icon' => 'ri-subtract-line'],
            3 => ['label' => 'Loans',         'icon' => 'ri-bank-card-line'],
            4 => ['label' => 'Refunds',       'icon' => 'ri-refund-2-line'],
        ];
        $groups = [];
        $dedTotal = $refTotal = 0.0;
        foreach ($labels as $type => $meta) {
            $items = [];
            foreach ($remit as $rm) {
                if ($rm['type'] !== $type) continue;
                $items[] = [
                    'name'      => $this->remit_type_label($type, $rm['id']),
                    'employees' => (int)$rm['employees'],
                    'total'     => round((float)$rm['total'], 2),
                ];
                if ($type === 4) $refTotal += $rm['total'];
                else             $dedTotal += $rm['total'];
            }
            if ($items) $groups[] = ['label' => $meta['label'], 'icon' => $meta['icon'], 'items' => $items];
        }

        // ── Named one-off items (payroll_item_extras) ──
        // Ad-hoc per-employee lines rather than configured types, so they get
        // their own groups, collapsed by label. One-off DEDUCTIONS are money
        // withheld and count toward Total Deductions; one-off ALLOWANCES are
        // payouts, so they are totalled separately rather than muddling either.
        $extraAddTotal = 0.0;
        if ($this->db->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
            $byKind = [1 => [], 2 => []];
            $xq = $this->db->query("SELECT kind, label, COUNT(*) AS employees, SUM(amount) AS total
                                    FROM payroll_item_extras WHERE payroll_id = $id
                                    GROUP BY kind, label ORDER BY label ASC");
            if ($xq) while ($x = $xq->fetch_assoc()) {
                $byKind[(int)$x['kind'] === 2 ? 2 : 1][] = [
                    'name'      => $x['label'],
                    'employees' => (int)$x['employees'],
                    'total'     => round((float)$x['total'], 2),
                ];
            }
            if ($byKind[1]) {
                $groups[] = ['label' => 'One-off Deductions', 'icon' => 'ri-scissors-cut-line', 'items' => $byKind[1]];
                foreach ($byKind[1] as $it) $dedTotal += $it['total'];
            }
            if ($byKind[2]) {
                $groups[] = ['label' => 'One-off Allowances', 'icon' => 'ri-gift-line', 'items' => $byKind[2]];
                foreach ($byKind[2] as $it) $extraAddTotal += $it['total'];
            }
        }

        $emp = $this->db->query("SELECT COUNT(DISTINCT employee_id) AS c FROM payroll_items
                                 WHERE payroll_id = $id")->fetch_assoc();

        return [
            'result'      => true,
            'groups'      => $groups,
            'ded_total'   => round($dedTotal, 2),
            'ref_total'   => round($refTotal, 2),
            'extra_add'   => round($extraAddTotal, 2),
            'employees'   => (int)($emp['c'] ?? 0),
        ];
    }

    /** Display name for one remittance type, from whichever table defines it. */
    private function remit_type_label($type, $ddId)
    {
        $ddId = (int)$ddId;
        $sql = [
            1 => "SELECT contribution AS n FROM contributions WHERE id = $ddId",
            2 => "SELECT deduction AS n FROM deductions WHERE id = $ddId",
            3 => "SELECT loan_type AS n FROM contribution_loan_types WHERE clt_id = $ddId",
            4 => "SELECT refunds AS n FROM refunds WHERE id = $ddId",
        ][(int)$type] ?? null;
        if (!$sql) return '#' . $ddId;
        $r = $this->db->query($sql);
        $row = $r ? $r->fetch_assoc() : null;
        return $row['n'] ?? ('#' . $ddId);
    }

    /**
     * Reopen ONE employee's row while the payroll is out for employee review, so
     * a disputed figure can be corrected without recalling the whole batch and
     * voiding everybody else's sign-off.
     *
     * Admin only: this rewrites money after employees have already seen it.
     * Unlocking alone changes no figures and voids no sign-off — that happens
     * only if an edit is actually saved (see void_signoff_for_item).
     */
    function unlock_payroll_item()
    {
        if ((int)($_SESSION['login_role'] ?? 0) !== 1) {
            return ['result' => false, 'message' => 'Only an administrator can unlock an employee for editing.'];
        }
        $itemId = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!$itemId) return ['result' => false, 'message' => 'Missing payroll item.'];
        if ($reason === '') return ['result' => false, 'message' => 'A reason is required to unlock an employee.'];
        if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255);

        $row = $this->db->query("SELECT i.payroll_id, i.employee_id, p.status,
                    CONCAT(e.lastname, ', ', e.firstname) AS name
                FROM payroll_items i
                INNER JOIN payroll p ON p.id = i.payroll_id
                INNER JOIN employee e ON e.id = i.employee_id
                WHERE i.id = $itemId")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Payroll item not found.'];
        if ((int)$row['status'] !== 3) {
            return ['result' => false, 'message' => (int)$row['status'] === 1
                ? 'This payroll is already open — no unlock needed.'
                : 'Only a payroll that is out for employee review can be unlocked per employee.'];
        }

        $uid = (int)($_SESSION['login_id'] ?? 0) ?: null;
        $st = $this->db->prepare("UPDATE payroll_items
                SET unlocked_at = NOW(), unlocked_by = ?, unlocked_reason = ? WHERE id = ?");
        $st->bind_param('isi', $uid, $reason, $itemId);
        if (!$st->execute()) return ['result' => false, 'message' => $st->error];

        $this->save_payroll_history((int)$row['payroll_id'], 5,
            'Unlocked ' . $row['name'] . ' for editing (' . $reason . ') —');

        return [
            'result'  => true,
            'message' => $row['name'] . ' is now editable. Saving a change will void their review and ask them to confirm again.',
            'name'    => $row['name'],
        ];
    }

    /** Re-freeze a row that was unlocked (also called automatically on re-confirm). */
    function relock_payroll_item()
    {
        if ((int)($_SESSION['login_role'] ?? 0) !== 1) {
            return ['result' => false, 'message' => 'Only an administrator can lock an employee.'];
        }
        $itemId = (int)($_POST['id'] ?? 0);
        if (!$itemId) return ['result' => false, 'message' => 'Missing payroll item.'];

        $row = $this->db->query("SELECT i.payroll_id, CONCAT(e.lastname, ', ', e.firstname) AS name
                FROM payroll_items i INNER JOIN employee e ON e.id = i.employee_id
                WHERE i.id = $itemId")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Payroll item not found.'];

        if (!$this->db->query("UPDATE payroll_items
                SET unlocked_at = NULL, unlocked_by = NULL, unlocked_reason = NULL
                WHERE id = $itemId")) {
            return ['result' => false, 'message' => $this->db->error];
        }
        $this->save_payroll_history((int)$row['payroll_id'], 5, 'Locked ' . $row['name'] . ' —');

        return ['result' => true, 'message' => $row['name'] . ' is locked again.', 'name' => $row['name']];
    }

    /**
     * An unlocked row was actually edited, so whatever the employee signed off on
     * no longer exists: drop their sign-off and ask them to review the corrected
     * payslip. Without this the batch would report them as "Confirmed" against
     * figures they never saw.
     */
    private function void_signoff_for_item($itemId)
    {
        $itemId = (int) $itemId;
        $row = $this->db->query("SELECT i.payroll_id, i.employee_id, p.status, p.date_from, p.date_to
                FROM payroll_items i
                INNER JOIN payroll p ON p.id = i.payroll_id
                WHERE i.id = $itemId")->fetch_assoc();
        if (!$row || (int)$row['status'] !== 3) return;     // only meaningful mid-review

        $pid = (int)$row['payroll_id'];
        $eid = (int)$row['employee_id'];
        $had = $this->db->query("SELECT id FROM payroll_employee_reviews
                WHERE payroll_id = $pid AND employee_id = $eid")->fetch_assoc();
        if (!$had) return;                                  // never reviewed — nothing to void

        $this->db->query("DELETE FROM payroll_employee_reviews
                WHERE payroll_id = $pid AND employee_id = $eid");
        // The reviewer's colour mark came from that sign-off; clear it too.
        $this->db->query("UPDATE payroll_items SET review_status = 0
                WHERE payroll_id = $pid AND employee_id = $eid AND review_status IN (1,2)");

        $period = date('M j', strtotime($row['date_from'])) . ' – ' . date('M j, Y', strtotime($row['date_to']));
        $this->notifyEmployee(
            $eid,
            'Your payslip was corrected',
            "Your payslip for $period was updated. Please review and confirm it again.",
            'ri-refresh-line',
            'warning',
            'employee-portal.php?tab=payslips'
        );
    }

    function update_payroll_item()
    {
        if ($block = $this->payroll_item_write_block($_POST['id'] ?? 0)) return $block;
        $this->db->begin_transaction();
        $id = $_POST['id'];
        $value = $_POST['value'];
        $field = $_POST['type'];
        $dd_id = (int) $_POST['dd_id'];
        $query = "SELECT loan_history.*, payroll.ref_no, payroll.date_from, payroll.date_to, payroll_items.employee_id FROM loan_history 
        INNER JOIN payroll ON  loan_history.payroll_id = payroll.id 
        INNER JOIN payroll_items ON  payroll_items.payroll_id = payroll.id
        WHERE loan_id = ?";
        $type = 4;
        $field2 = $dd_id;
        $value2 = $value;
        try {
            if (isset($dd_id)) {
                $query = "SELECT  contributions, deductions, loans, refunds, payroll.id AS payroll_id, employee_id FROM payroll_items INNER JOIN payroll ON  payroll_items.payroll_id = payroll.id WHERE  payroll_items.id = ?";
                $stmt =  $this->db->prepare($query);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $payroll_r = $result->fetch_assoc();

                if ($field === 'contribution') {
                    $contricutions = json_decode($payroll_r['contributions'], true);
                    $updatedContributions = $this->updateContributionAmount($contricutions, $dd_id, $value, 'contribution_id');
                    var_dump($updatedContributions) or die();
                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'contribution_id' => (int) $dd_id,
                            'amount' =>  (float) $value
                        ];
                        $new_contributions =  array_merge($contricutions, $array);
                        $value = json_encode($new_contributions);
                        $field = "contributions";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "contributions";
                    }
                    $type = 7;
                }

                if ($field === 'deduction') {
                    $deductions = json_decode(($payroll_r['deductions']), true);
                    $updatedContributions = $this->updateContributionAmount($deductions, $dd_id, $value, 'deduction_id');

                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'deduction_id' => (int) $dd_id,
                            'amount' => (float) $value
                        ];
                        $new_deductions =  array_merge($deductions, $array);
                        $value = json_encode($new_deductions);
                        $field = "deductions";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "deductions";
                    }
                    $type = 8;
                }

                if ($field === 'loan') {
                    $deductions = json_decode(($payroll_r['loans']), true);
                    $updatedContributions = $this->updateContributionAmount($deductions, $dd_id, $value, 'deduction_id');
                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'deduction_id' => (int) $dd_id,
                            'amount' => (float) $value
                        ];
                        $new_deductions =  array_merge($deductions, $array);
                        $value = json_encode($new_deductions);
                        $field = "loans";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "loans";
                    }
                    $type = 9;
                }

                if ($field === 'refund') {
                    $deductions = json_decode(($payroll_r['refunds']), true);
                    $updatedContributions = $this->updateContributionAmount($deductions, $dd_id, $value, 'refund_id');
                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'refund_id' => (int) $dd_id,
                            'amount' => (float)  $value
                        ];
                        $new_deductions =  array_merge($deductions, $array);
                        $value = json_encode($new_deductions);
                        $field = "refunds";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "refunds";
                    }
                    $type = 10;
                }
            }
            $query_update = "UPDATE payroll_items SET $field = ? WHERE id = ?";

            $stmt3 = $this->db->prepare($query_update);
            if ($stmt3 === false) {
                throw new Exception('Failed to prepare the statement: ' . $this->db->error);
            }
            $stmt3->bind_param("si", $value, $id);
            try {
                $stmt3->execute();
            } catch (Exception $e) {
                throw new Exception('Failed to update data: ' . $e->getMessage());
            }

            $this->save_payroll_history($payroll_r['payroll_id'], $type, [["value" => $value, "field" => $field, "employee_id" => $payroll_r['employee_id']]],  $field2, $value2);
            $this->db->commit();
            // Editing an unlocked row invalidates what that employee confirmed.
            $this->void_signoff_for_item($id);
            return ['result' => true, 'message' => 'save'];
        } catch (mysqli_sql_exception $e) {
            return ['result' => false, 'message' => $e->getMessage()];
        }
        return ['result' => false, 'message' => 'save'];
    }

    function update_payroll_item_new()
    {
        $items = $_POST['items'];
        if (!is_array($items) || !$items) return ['result' => false, 'message' => 'Nothing to save.'];

        // Every row in the batch has to be writable, checked before anything is
        // written — a partial save would leave the sheet half-updated.
        foreach ($items as $item) {
            if ($block = $this->payroll_item_write_block($item['id'] ?? 0)) return $block;
        }

        try {
            $this->db->begin_transaction();

            foreach ($items as $item) {
                $id = $item['id'];              // payroll_item ID
                $field = $item['type'];
                // adjustment_remarks is free text — everything else is numeric.
                $value = ($field === 'adjustment_remarks')
                    ? trim((string) $item['value'])
                    : (float) $item['value'];
                $dd_id = isset($item['dd_id']) ? (int) $item['dd_id'] : 0;

                // Save the payroll item
                $this->save_new_payroll_item($id, $value, $field, $dd_id);

                // Handle per_day type
                if ($field === 'per_day') {
                    // First get the employee_id from payroll_items table
                    $getEmployeeQuery = "SELECT employee_id FROM payroll_items WHERE id = ?";
                    $stmt = $this->db->prepare($getEmployeeQuery);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $employee_id = $row['employee_id'];

                        // Calculate monthly salary (assuming 22 working days)
                        $salary = $value;

                        // Update the employee's salary in the database
                        $updateQuery = "UPDATE employee SET salary = ? WHERE id = ?";
                        $stmt = $this->db->prepare($updateQuery);
                        $stmt->bind_param("di", $salary, $employee_id);
                        $stmt->execute();
                    }
                }
            }

            $this->db->commit();
            // Editing an unlocked row invalidates what that employee confirmed.
            // Deduped: one sheet save can carry several fields for the same row.
            $voided = [];
            foreach ($items as $item) {
                $iid = (int)($item['id'] ?? 0);
                if (!$iid || isset($voided[$iid])) continue;
                $voided[$iid] = true;
                $this->void_signoff_for_item($iid);
            }
            return ['result' => true, 'message' => 'save'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Error updating payroll items: ' . $e->getMessage()];
        }
    }

    function compare_payrolls()
    {
        $id_a = (int)$_POST['id_a'];
        $id_b = (int)$_POST['id_b'];
        if (!$id_a || !$id_b) return ['result' => false, 'message' => 'Invalid payroll IDs'];

        $fetch = function ($id) {
            $q = $this->db->prepare("
                SELECT pi.employee_id,
                    CONCAT(e.lastname,', ',e.firstname) AS name,
                    e.employee_no,
                    pi.basic_pay                                                        AS basic,
                    " . sql_gross('pi') . "                                             AS gross,
                    pi.net
                FROM payroll_items pi
                INNER JOIN employee e ON pi.employee_id = e.id
                WHERE pi.payroll_id = ?
                ORDER BY e.lastname, e.firstname
            ");
            $q->bind_param('i', $id);
            $q->execute();
            $rows = [];
            foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $rows[$r['employee_id']] = $r;
            }
            return $rows;
        };

        $a = $fetch($id_a);
        $b = $fetch($id_b);

        // Payroll labels
        $lbl = function ($id) {
            $r = $this->db->query("SELECT ref_no, date_from, date_to FROM payroll WHERE id=$id")->fetch_assoc();
            return $r ? $r['ref_no'] . ' (' . date('M d', strtotime($r['date_from'])) . '–' . date('M d,Y', strtotime($r['date_to'])) . ')' : "Payroll #$id";
        };

        // Merge all unique employees
        $all_ids = array_unique(array_merge(array_keys($a), array_keys($b)));
        $rows = [];
        foreach ($all_ids as $eid) {
            $ra = $a[$eid] ?? null;
            $rb = $b[$eid] ?? null;
            $ref = $ra ?? $rb;
            $rows[] = [
                'employee_id' => $eid,
                'name'        => $ref['name'],
                'employee_no' => $ref['employee_no'],
                'a'           => $ra ? ['basic' => $ra['basic'], 'gross' => $ra['gross'], 'net' => $ra['net']] : null,
                'b'           => $rb ? ['basic' => $rb['basic'], 'gross' => $rb['gross'], 'net' => $rb['net']] : null,
            ];
        }

        usort($rows, fn($x, $y) => strcmp($x['name'], $y['name']));

        return ['result' => true, 'label_a' => $lbl($id_a), 'label_b' => $lbl($id_b), 'rows' => $rows];
    }

    function get_payroll_rows_data()
    {
        $payroll_id = (int)$_POST['payroll_id'];
        $q = "SELECT pi.*,
                e.lastname, e.firstname, e.basic_pay AS emp_basic_pay,
                p.name AS position, s.site_code, s.site_name,
                pay.settings, pay.type AS payroll_type
              FROM payroll_items pi
              INNER JOIN employee e ON pi.employee_id = e.id
              LEFT JOIN position p ON e.position_id = p.id
              LEFT JOIN sites s ON pi.site_id = s.id
              INNER JOIN payroll pay ON pi.payroll_id = pay.id
              WHERE pi.payroll_id = ?
              ORDER BY e.lastname ASC";
        $stmt = $this->db->prepare($q);
        $stmt->bind_param("i", $payroll_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        $t_gross = 0;
        $t_net = 0;
        $t_deductions = 0;
        $t_absent = 0;
        $t_late = 0;

        // Named one-off items per payroll_items row, if the feature's table exists.
        $extrasByItem = [];
        $hasExtras = $this->db->query("SHOW TABLES LIKE 'payroll_item_extras'");
        if ($hasExtras && $hasExtras->num_rows) {
            $xq = $this->db->query("SELECT payroll_item_id, kind, amount FROM payroll_item_extras
                                    WHERE payroll_id = " . (int)$payroll_id);
            if ($xq) while ($x = $xq->fetch_assoc()) $extrasByItem[(int)$x['payroll_item_id']][] = $x;
        }

        while ($row = $result->fetch_assoc()) {
            $payroll_type     = isset($row['payroll_type']) ? (int) $row['payroll_type'] : 0;
            // Hours in this employee's working day, frozen on the item at calc time.
            $dayHours         = day_hours_or_default($row['day_hours'] ?? null);
            $perMinute        = $row['per_day'] / ($dayHours * 60);
            // ONE formula for every screen, sheet, export and dashboard —
            // payroll_earnings() in db_connect.php. This block used to be its own
            // copy, which is how the register, the portal and this stored net
            // drifted apart. Names below are kept so the rest of the method and
            // its callers are untouched.
            $__e              = payroll_earnings($row);
            $allowance_total  = $__e['allowance'];
            $absent_amount    = $__e['absent_amt'];
            $overtime_amount  = $__e['overtime'];
            $late_amount      = $__e['late_amt'];
            $legal_amount     = $__e['legal_amt'];
            $sunday_amount    = $__e['rest_amt'];
            $special_amount   = $__e['special_amt'];
            $nsd_amount       = $__e['nsd_amt'];
            $is_monthly       = $__e['is_monthly'];
            $total_basic_rate = $__e['basic'];
            $total_amount     = $__e['subtotal'];
            $gross            = $__e['gross'];

            $contributions = json_decode($row['contributions'], true) ?: [];
            $deductions    = json_decode($row['deductions'],    true) ?: [];
            $loans         = json_decode($row['loans'],         true) ?: [];
            $refunds_data  = json_decode($row['refunds'],       true) ?: [];
            $total_ded = 0;
            $total_ref = 0;
            foreach ($contributions as $c) $total_ded += floatval($c['amount'] ?? 0);
            foreach ($deductions    as $c) $total_ded += floatval($c['amount'] ?? 0);
            foreach ($loans         as $c) $total_ded += floatval($c['amount'] ?? 0);
            $total_ded += floatval($row['sss_fund']) + floatval($row['jei_advances']) + floatval($row['jcc_advances']) + floatval($row['tax']) + floatval($row['other_deduction']);
            foreach ($refunds_data as $r) $total_ref += floatval($r['amount'] ?? 0);
            // Manual signed adjustment (+ addition / − recovery) applied to net.
            $adjustment = floatval($row['adjustment'] ?? 0);
            // Named one-off items for this employee (payroll_item_extras):
            // kind 1 deducts, kind 2 adds. Folded in here so the figures this
            // endpoint sends back after a save match the rendered sheet.
            $xAdd = $xLess = 0.0;
            foreach (($extrasByItem[(int)$row['id']] ?? []) as $x) {
                if ((int)$x['kind'] === 2) $xAdd  += (float)$x['amount'];
                else                       $xLess += (float)$x['amount'];
            }
            $gross     += $xAdd;        // allowance extras belong in gross
            $total_ded += $xLess;
            $net = $gross - $total_ded + $total_ref + $adjustment;

            $t_gross      += $gross;
            $t_net        += $net;
            $t_deductions += $total_ded;
            $t_absent     += $row['absent'];
            $t_late       += $row['late'];

            $rows[] = [
                'id'                   => $row['id'],
                'present'              => $row['present'],
                'per_day'              => $row['per_day'],
                'total_basic_rate'     => $total_basic_rate,
                'allowance_total'      => $allowance_total,
                'absent_amount'        => $absent_amount,
                'total_amount'         => $total_amount,
                'overtime_amount'      => $overtime_amount,
                'late_amount'          => $late_amount,
                'legal_amount'         => $legal_amount,
                'sunday_amount'        => $sunday_amount,
                'special_amount'       => $special_amount,
                'nsd_hours'            => floatval($row['nsd_hours'] ?? 0),
                'nsd_amount'           => $nsd_amount,
                'gross'                => $gross,
                'net'                  => $net,
                'total_deductions'     => $total_ded,
                'other_deduction'      => floatval($row['other_deduction'] ?? 0),
                'adjustment'           => $adjustment,
                'absent'               => $row['absent'],
                'paid_leave'           => $row['paid_leave'] ?? 0,
                'paid_leave_amount'    => ($row['paid_leave'] ?? 0) * $row['per_day'],
                'late'                 => $row['late'],
                'rate_type'            => $row['rate_type'] ?? 'daily',
            ];
        }

        return [
            'result' => true,
            'rows'   => $rows,
            'totals' => [
                'gross'      => $t_gross,
                'net'        => $t_net,
                'deductions' => $t_deductions,
                'absent'     => $t_absent,
                'late'       => $t_late,
            ]
        ];
    }

    function save_new_payroll_item($id, $value, $field, $dd_id)
    {
        // Whitelist — $field lands in the UPDATE statement below, so only known
        // payroll_items columns / JSON pseudo-fields may pass.
        static $allowed_fields = [
            'present', 'per_day', 'allowance_days', 'allowance_amount', 'ot', 'ot_rate',
            'late', 'under_time', 'absent', 'legal_holiday', 'sunday_duty', 'special_holiday',
            'nsd_amount',
            'sss_fund', 'jei_advances', 'jcc_advances', 'tax', 'other_deduction',
            'adjustment', 'adjustment_remarks', 'net',
            'contribution', 'deduction', 'loan', 'refund',
        ];
        if (!in_array($field, $allowed_fields, true)) {
            throw new Exception('Unknown payroll item field: ' . $field);
        }
        // A hand-typed tax is a deliberate override and must survive both a
        // recalculation and the auto-post pass. Flagged here, at the one place
        // an admin can actually type it, so the flag can never disagree with
        // what is in the column. Clearing the field releases the override.
        if ($field === 'tax') {
            $ov = ((float) $value) != 0.0 ? 1 : 0;
            $st = $this->db->prepare("UPDATE payroll_items SET tax_override = ? WHERE id = ?");
            if ($st) { $st->bind_param('ii', $ov, $id); $st->execute(); }
        }
        $this->db->begin_transaction();
        $query = "SELECT loan_history.*, payroll.ref_no, payroll.date_from, payroll.date_to, payroll_items.employee_id FROM loan_history 
        INNER JOIN payroll ON  loan_history.payroll_id = payroll.id 
        INNER JOIN payroll_items ON  payroll_items.payroll_id = payroll.id
        WHERE loan_id = ?";
        $type = 4;
        $field2 =   $dd_id;
        $value2 = $value;
        try {
            if (isset($dd_id)) {
                $query = "SELECT  contributions, deductions, loans, refunds, payroll.id AS payroll_id, employee_id FROM payroll_items INNER JOIN payroll ON  payroll_items.payroll_id = payroll.id WHERE  payroll_items.id = ?";
                $stmt =  $this->db->prepare($query);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $payroll_r = $result->fetch_assoc();

                if ($field === 'contribution') {
                    $contricutions = json_decode($payroll_r['contributions'], true);
                    $updatedContributions = $this->updateContributionAmount($contricutions, $dd_id, $value, 'contribution_id');
                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'contribution_id' => (int) $dd_id,
                            'amount' =>  (float) $value
                        ];
                        $new_contributions =  array_merge($contricutions, $array);
                        $value = json_encode($new_contributions);
                        $field = "contributions";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "contributions";
                    }
                    $type = 7;
                }

                if ($field === 'deduction') {
                    $deductions = json_decode(($payroll_r['deductions']), true);
                    $updatedContributions = $this->updateContributionAmount($deductions, $dd_id, $value, 'deduction_id');

                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'deduction_id' => (int) $dd_id,
                            'amount' => (float) $value
                        ];
                        $new_deductions =  array_merge($deductions, $array);
                        $value = json_encode($new_deductions);
                        $field = "deductions";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "deductions";
                    }
                    $type = 8;
                }

                if ($field === 'loan') {
                    $deductions = json_decode(($payroll_r['loans']), true);
                    $updatedContributions = $this->updateContributionAmount($deductions, $dd_id, $value, 'deduction_id');
                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'deduction_id' => (int) $dd_id,
                            'amount' => (float) $value
                        ];
                        $new_deductions =  array_merge($deductions, $array);
                        $value = json_encode($new_deductions);
                        $field = "loans";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "loans";
                    }
                    $type = 9;
                }

                if ($field === 'refund') {
                    $deductions = json_decode(($payroll_r['refunds']), true);
                    $updatedContributions = $this->updateContributionAmount($deductions, $dd_id, $value, 'refund_id');
                    if (count($updatedContributions) === 0) {
                        $array[] = (object) [
                            'refund_id' => (int) $dd_id,
                            'amount' => (float)  $value
                        ];
                        $new_deductions =  array_merge($deductions, $array);
                        $value = json_encode($new_deductions);
                        $field = "refunds";
                    } else {
                        $value = json_encode($updatedContributions);
                        $field = "refunds";
                    }
                    $type = 10;
                }
            }
            $query_update = "UPDATE payroll_items SET $field = ? WHERE id = ?";

            $stmt3 = $this->db->prepare($query_update);
            if ($stmt3 === false) {
                throw new Exception('Failed to prepare the statement: ' . $this->db->error);
            }
            $stmt3->bind_param("si", $value, $id);
            try {
                $stmt3->execute();
            } catch (Exception $e) {
                throw new Exception('Failed to update data: ' . $e->getMessage());
            }
            $this->save_payroll_history($payroll_r['payroll_id'], $type, [["value" => $value, "field" => $field, "employee_id" => $payroll_r['employee_id']]],  $field2, $value2);
            $this->db->commit();
        } catch (mysqli_sql_exception $e) {
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    function save_payroll_amount()
    {
        $ids  = $_POST['id']  ?? [];
        $nets = $_POST['net'] ?? [];
        if (!is_array($ids) || !is_array($nets) || !$ids) {
            return ['result' => false, 'message' => 'Invalid parameters'];
        }

        // A LOCKED payroll (status 2) is paid history: its loan deductions are
        // committed and its figures have been approved. This endpoint used to
        // UPDATE payroll_items.net with no reference to the parent payroll at
        // all, so a locked period's amounts could be rewritten by posting
        // straight to it — bypassing the UI, with no audit entry. Resolve the
        // parent for every id and refuse if any belongs to a locked run.
        $idList = implode(',', array_map('intval', $ids));
        $locked = [];
        $parents = [];
        $res = $this->db->query(
            "SELECT pi.id, pi.payroll_id, p.status
             FROM payroll_items pi INNER JOIN payroll p ON p.id = pi.payroll_id
             WHERE pi.id IN ($idList)"
        );
        while ($res && $r = $res->fetch_assoc()) {
            $parents[(int)$r['id']] = (int)$r['payroll_id'];
            if ((int)$r['status'] === 2) $locked[] = (int)$r['id'];
        }
        if ($locked) {
            return ['result' => false, 'message' => 'Cannot edit a locked payroll. Unlock it first.'];
        }
        // Any id with no parent row is an unknown/orphaned payslip — refuse
        // rather than writing money to a record we cannot attribute to a run.
        foreach ($ids as $k) {
            if (!isset($parents[(int)$k])) {
                return ['result' => false, 'message' => 'Unknown payroll item: ' . (int)$k];
            }
        }

        $stmt3 = $this->db->prepare("UPDATE payroll_items SET net = ? WHERE id = ?");
        if ($stmt3 === false) {
            throw new Exception('Failed to prepare the statement: ' . $this->db->error);
        }

        $this->db->begin_transaction();
        try {
            foreach ($ids as $index => $k) {
                $id  = (int) $k;
                $net = $nets[$index] ?? null;
                if ($net === null) continue;
                $stmt3->bind_param("di", $net, $id);
                // No empty catch here: a failed write used to be swallowed and
                // then commit()ed, reporting success for money that never saved.
                if (!$stmt3->execute()) {
                    throw new Exception('Failed to update payroll item ' . $id . ': ' . $this->db->error);
                }
            }
            // Every amount edit is auditable — who changed a net, and when.
            foreach (array_unique(array_values($parents)) as $pid) {
                $this->save_payroll_history($pid, 5, 'Edit net amount');
            }
            $this->db->commit();
            return ['result' => true, 'message' => 'save'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    function isLock()
    {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = $_POST['isLock'];
        if (!$id) {
            return ['result' => false, 'message' => 'Invalid parameters'];
        }

        // $status is the payroll's NEW status: 0/1 = unlocking, 2 = locking.
        $isUnlock = ((int)$status !== 2);
        $this->save_payroll_history($id, 5, $isUnlock ? 'Unlock' : 'Lock');

        $this->db->begin_transaction();
        try {
            // Unlocking must reverse the loan deductions that Lock committed for
            // this payroll, so it can be recalculated/re-locked without drifting
            // loan balances. This is the exact inverse of update_payroll_status().
            if ($isUnlock) {
                $hist = $this->db->query("SELECT loan_id, amount FROM loan_history WHERE payroll_id = " . intval($id));
                while ($h = $hist->fetch_assoc()) {
                    $restore = $this->db->prepare("UPDATE loans SET loan_balance = loan_balance + ?, loan_status = 0 WHERE loan_id = ?");
                    $restore->bind_param("di", $h['amount'], $h['loan_id']);
                    $restore->execute();
                    $restore->close();
                }
                // Loan deductions are undone; drop the history so a re-lock re-commits cleanly.
                $this->db->query("DELETE FROM loan_history WHERE payroll_id = " . intval($id));

                // Same reversal for amortizing employee deductions.
                $dhist = $this->db->query("SELECT ded_id, amount FROM deduction_history WHERE payroll_id = " . intval($id));
                while ($dh = $dhist->fetch_assoc()) {
                    $drestore = $this->db->prepare("UPDATE employee_deductions SET balance = balance + ?, status = 0 WHERE id = ?");
                    $drestore->bind_param("di", $dh['amount'], $dh['ded_id']);
                    $drestore->execute();
                    $drestore->close();
                }
                $this->db->query("DELETE FROM deduction_history WHERE payroll_id = " . intval($id));
            }

            $stmt = $this->db->prepare("UPDATE payroll SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();

            $this->db->commit();
            return ['result' => true, 'message' => 'updated'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    function saveLogs()
    {
        define('UPLOAD_DIR', 'uploads/');
        $post = json_decode(file_get_contents('php://input'), true);
        $company = $post['company'];
        $name = $post['name'];
        $site_id = $post['site_id'];
        $image = $post['image'];
        $date_visited = $post['date_visited'];
        $date = new DateTime($date_visited);
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        $formattedDate = $date->format('Y-m-d H:i:s');
        $image_data = base64_decode($image);
        $filename =  uniqid() . '.jpg';
        $this->db->begin_transaction();
        $file_path = UPLOAD_DIR . $filename;
        try {
            $sql2 = "INSERT INTO visitors_logs (site_id, image, name, company, date_visited) VALUES (?, ?, ?, ?, ?)";
            $stmtbio = $this->db->prepare($sql2);
            $stmtbio->bind_param('sssss', $site_id, $filename, $name, $company, $formattedDate);
            try {
                $stmtbio->execute();
                file_put_contents($file_path, $image_data);
            } catch (Exception $e) {
                throw new Exception('Failed to insert data');
            }
            $this->db->commit();
            return ['result' => true, 'message' => 'Data inserted successfully'];
        } catch (Exception $e) {
            $this->db->rollback(); // Rollback on errors
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }


    function save_employee_loan()
    {
        extract($_POST);
        $loan_status = isset($_POST['loan_status']) ? 1 : 0;
        $data = " employee_id=$employee_id ";
        $data .= ", loan_date='$loan_date' ";
        // Optional deduction start date; blank falls back to loan_date at calc time.
        $eff = trim($_POST['effective_date'] ?? '');
        $eff_ok = $eff !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff);
        $data .= ", effective_date=" . ($eff_ok ? "'" . $this->db->real_escape_string($eff) . "'" : "NULL") . " ";
        $data .= ", loan_amount = $loan_amount ";
        $data .= ", loan_status = $loan_status ";
        $data .= ", loan_type = $loan_type ";
        $data .= ", loan_balance = $loan_balance ";
        $data .= ", damount = $damount ";
        // Optional supporting document (image/PDF ≤ 5 MB, shared helper). An
        // edit that sends no new file keeps the stored one — the column is
        // simply left out of the UPDATE.
        $up = payroll_save_attachment('attachment', 'loan');
        if (!$up['ok']) {
            return $up['error'];
        }
        if ($up['file'] !== null) {
            $data .= ", attachment='" . $this->db->real_escape_string($up['file']) . "' ";
        }
        if (empty($id)) {
            $save = $this->db->query("INSERT INTO loans SET " . $data);
            return 1;
        } else {
            $this->db->query("UPDATE loans set " . $data . " where loan_id=" . $id);
            return 2;
        }
    }

    function active_employee_loan()
    {
        extract($_POST);
        $data = " loan_id=$loan_id ";
        $data .= ", loan_deduction='$loan_deduction' ";
        $data .= ", loan='$loan' ";
        $this->db->query("UPDATE employee set " . $data . " where id=" . $id);
        return 1;
    }

    /**
     * Pre-lock sanity check — everything an admin should eyeball before Lock,
     * computed in one pass over the payroll's items (same math as the page):
     *   negative  — net pay ≤ 0
     *   zero_days — no days present (fixed-rate staff excluded: paid regardless)
     *   swings    — net changed more than sanity_net_swing_pct% vs the previous period
     *   missing   — expected working days (rest days excluded) not accounted for
     *               by present + paid leave + absent
     */
    function payroll_sanity_check()
    {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Invalid payroll.'];
        $pay = $this->db->query("SELECT * FROM payroll WHERE id = $id")->fetch_assoc();
        if (!$pay) return ['result' => false, 'message' => 'Payroll not found.'];

        $threshold = 30.0;
        $ts = $this->db->query("SELECT setting_value FROM pay_settings WHERE setting_key = 'sanity_net_swing_pct'");
        if ($ts && ($tr = $ts->fetch_assoc())) $threshold = max(1, (float) $tr['setting_value']);

        // Current rows via the same computation the details page refreshes with.
        $keep = $_POST;
        $_POST = ['payroll_id' => $id];
        $data = $this->get_payroll_rows_data();
        $_POST = $keep;

        // Names + employee ids per payroll item.
        $names = [];
        $emps = [];
        $nq = $this->db->query("SELECT pi.id, pi.employee_id, CONCAT(e.lastname, ', ', e.firstname) AS name
            FROM payroll_items pi INNER JOIN employee e ON e.id = pi.employee_id WHERE pi.payroll_id = $id");
        if ($nq) while ($n = $nq->fetch_assoc()) {
            $names[$n['id']] = $n['name'];
            $emps[$n['id']] = (int) $n['employee_id'];
        }

        // Previous period's net per employee (nearest earlier payroll).
        $prevNet = [];
        $prev = $this->db->query("SELECT id, date_from, date_to FROM payroll
            WHERE date_from < '" . $this->db->real_escape_string($pay['date_from']) . "' AND id <> $id
            ORDER BY date_from DESC, id DESC LIMIT 1")->fetch_assoc();
        if ($prev) {
            $pq = $this->db->query("SELECT employee_id, SUM(net) AS n FROM payroll_items WHERE payroll_id = {$prev['id']} GROUP BY employee_id");
            if ($pq) while ($p = $pq->fetch_assoc()) $prevNet[(int) $p['employee_id']] = (float) $p['n'];
        }

        // Rest-day schedules overlapping the period → expected working days.
        // All periods — same reason as the generation prefetch: this check counts
        // expected work days via isRestDay(), and an employee with no overlapping
        // period would have every day counted as expected.
        $restMap = [];
        $rq = $this->db->prepare("SELECT employee_id, effective_from, effective_to, rest_days
            FROM employee_schedules
            ORDER BY effective_from DESC");
        $rq->execute();
        $rr = $rq->get_result();
        while ($rw = $rr->fetch_assoc()) $restMap[$rw['employee_id']][] = $rw;
        $this->loadDutyRestMap($pay['date_from'], $pay['date_to']);

        // Declared holidays in the period — excluded from expected work days here
        // too, so this sanity check agrees with the generation absent logic.
        $holidayDates = [];
        $hq = $this->db->prepare("SELECT start_date, end_date FROM calendar_events
            WHERE type IN (1, 3) AND start_date <= ? AND COALESCE(end_date, start_date) >= ?");
        $hq->bind_param('ss', $pay['date_to'], $pay['date_from']);
        $hq->execute();
        $hres = $hq->get_result();
        while ($h = $hres->fetch_assoc()) {
            $hEnd = $h['end_date'] ?: $h['start_date'];
            for ($d = strtotime($h['start_date']); $d <= strtotime($hEnd); $d = strtotime('+1 day', $d)) {
                $holidayDates[date('Y-m-d', $d)] = true;
            }
        }

        $negative = [];
        $zero = [];
        $swings = [];
        $missing = [];
        $nd_zero = [];
        foreach ($data['rows'] as $r) {
            $iid = $r['id'];
            $eid = $emps[$iid] ?? 0;
            $nm = $names[$iid] ?? ('#' . $iid);
            $net = (float) $r['net'];
            $isFixed = ($r['rate_type'] ?? 'daily') === 'fixed';

            if ($net <= 0) $negative[] = ['name' => $nm, 'net' => round($net, 2)];
            if (!$isFixed && (float) $r['present'] <= 0) $zero[] = ['name' => $nm];
            // Night hours logged but the premium never priced — the shift's
            // has_nsd flag is off (or its nsd_rate is 0). Silently unpaid ND.
            if ((float) ($r['nsd_hours'] ?? 0) > 0 && (float) ($r['nsd_amount'] ?? 0) <= 0) {
                $nd_zero[] = ['name' => $nm, 'hours' => round((float) $r['nsd_hours'], 2)];
            }
            if (isset($prevNet[$eid]) && $prevNet[$eid] > 0) {
                $pct = ($net - $prevNet[$eid]) / $prevNet[$eid] * 100;
                if (abs($pct) > $threshold) {
                    $swings[] = ['name' => $nm, 'prev' => round($prevNet[$eid], 2), 'net' => round($net, 2), 'pct' => round($pct, 1)];
                }
            }
            if (!$isFixed) {
                $expected = 0;
                for ($d = strtotime($pay['date_from']); $d <= strtotime($pay['date_to']); $d = strtotime('+1 day', $d)) {
                    $symd = date('Y-m-d', $d);
                    if (!$this->isRestDay($restMap[$eid] ?? [], $symd, $eid) && empty($holidayDates[$symd])) $expected++;
                }
                $counted = (float) $r['present'] + (float) ($r['paid_leave'] ?? 0) + (float) $r['absent'];
                $miss = $expected - $counted;
                if ($miss >= 1) {
                    $missing[] = ['name' => $nm, 'expected' => $expected, 'counted' => round($counted, 2), 'missing' => round($miss, 1)];
                }
            }
        }
        usort($swings, fn($a, $b) => abs($b['pct']) <=> abs($a['pct']));

        // Arithmetic reconciliation. Every check above asks whether a figure looks
        // PLAUSIBLE; this one asks whether it ADDS UP — that the stored net is the
        // net its own components produce. Nothing verified that before, so a stale
        // or drifted net could reach the bank file unchallenged.
        $rec = $this->payroll_reconcile($id);
        $unbalanced = ($rec['result'] ?? false) ? $rec['mismatches'] : [];

        return [
            'result' => true,
            'total' => count($data['rows']),
            'threshold' => $threshold,
            'prev_label' => $prev ? date('M j', strtotime($prev['date_from'])) . '–' . date('M j, Y', strtotime($prev['date_to'])) : null,
            'negative' => $negative,
            'zero_days' => $zero,
            'swings' => $swings,
            'missing' => $missing,
            'nd_zero' => $nd_zero,
            'unbalanced' => $unbalanced,
            'reconciled' => ($rec['result'] ?? false) ? (bool) $rec['ok'] : null,
            'recon_totals' => ($rec['result'] ?? false) ? $rec['totals'] : null,
        ];
    }

    function update_payroll_status()
    {
        extract($_POST);

        // Start Transaction
        $this->db->begin_transaction();

        try {
            // Guard: if this payroll is already locked, don't re-commit loan
            // deductions. Loans commit exactly once (at Lock) and are reversed
            // at Unlock; without this guard a second Lock would double-deduct.
            $cur = $this->db->query("SELECT status FROM payroll WHERE id = " . intval($id))->fetch_assoc();
            if ($cur && (int)$cur['status'] === 2) {
                $this->db->commit();
                return 1;
            }

            $sql = "SELECT * FROM payroll_items WHERE payroll_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                foreach ($result as $row) {
                    $loans = json_decode($row['loans'], true);
                    $employee_id = $row['employee_id'];

                    foreach ($loans as $loan_d) {
                        $loan_query = "SELECT * FROM loans WHERE loan_type = ? AND employee_id = ?";
                        $loan_stmt = $this->db->prepare($loan_query);
                        $loan_stmt->bind_param("ii", $loan_d['deduction_id'], $row['employee_id']);
                        $loan_stmt->execute();
                        $loan_list = $loan_stmt->get_result()->fetch_array();

                        if ($loan_list) {
                            $loan_id = $loan_list['loan_id'];
                            $amount = $loan_d['amount'];
                            $current_bal = $loan_list['loan_balance'];
                            $payroll_id = $id;
                            // Cap the deduction at the outstanding balance BEFORE
                            // computing new_bal, so a final payment never records a
                            // negative new_bal in loan_history.
                            if ($current_bal < $amount) {
                                $amount = $current_bal;
                            }
                            $new_bal = $current_bal - $amount;

                            // Update loan status if fully paid
                            if ($new_bal <= 0) {
                                $loan_status_query = "UPDATE loans SET loan_status = 1, loan_balance = 0 WHERE loan_id = ?";
                                $loan_status_stmt = $this->db->prepare($loan_status_query);
                                if (!$loan_status_stmt) {
                                    throw new Exception("Query preparation failed: " . $this->db->error);
                                }
                                $loan_status_stmt->bind_param("i", $loan_id);
                                if (!$loan_status_stmt->execute()) {
                                    throw new Exception("Execution failed: " . $loan_status_stmt->error);
                                }
                                $loan_status_stmt->close();
                            } else {
                                $loan_status_query = "UPDATE loans SET loan_balance = ? WHERE loan_id = ?";
                                $loan_status_stmt = $this->db->prepare($loan_status_query);
                                if (!$loan_status_stmt) {
                                    throw new Exception("Query preparation failed: " . $this->db->error);
                                }
                                $loan_status_stmt->bind_param("di", $new_bal, $loan_id); // "d" for double (float), "i" for integer
                                if (!$loan_status_stmt->execute()) {
                                    throw new Exception("Execution failed: " . $loan_status_stmt->error);
                                }
                                $loan_status_stmt->close();
                            }

                            // Insert into loan history
                            $loan_history_query = "INSERT INTO loan_history (loan_id, amount, current_bal, new_bal, payroll_id, employee_id) VALUES (?, ?, ?, ?, ?, ?)";
                            $loan_history_stmt = $this->db->prepare($loan_history_query);
                            $loan_history_stmt->bind_param("idddii", $loan_id, $amount, $current_bal, $new_bal, $payroll_id, $employee_id);
                            $loan_history_stmt->execute();
                        }
                    }

                    // Commit amortizing employee deductions exactly like loans:
                    // decrement the deduction's balance, mark it paid when it hits 0,
                    // and record the movement in deduction_history (reversed at Unlock).
                    $ded_items = json_decode($row['deductions'], true) ?: [];
                    foreach ($ded_items as $ded_d) {
                        if (empty($ded_d['amortizing']) || empty($ded_d['ded_row_id'])) continue;
                        $ded_row_id = (int) $ded_d['ded_row_id'];
                        $ded_amount = (float) $ded_d['amount'];
                        $ded = $this->db->query("SELECT balance FROM employee_deductions WHERE id = $ded_row_id")->fetch_assoc();
                        if (!$ded) continue;
                        $current_bal = (float) $ded['balance'];
                        if ($current_bal < $ded_amount) $ded_amount = $current_bal;
                        $new_bal = $current_bal - $ded_amount;
                        if ($new_bal <= 0) {
                            $this->db->query("UPDATE employee_deductions SET balance = 0, status = 1 WHERE id = $ded_row_id");
                        } else {
                            $du = $this->db->prepare("UPDATE employee_deductions SET balance = ? WHERE id = ?");
                            $du->bind_param("di", $new_bal, $ded_row_id);
                            $du->execute();
                            $du->close();
                        }
                        $dh = $this->db->prepare("INSERT INTO deduction_history (ded_id, amount, current_bal, new_bal, payroll_id, employee_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $dh->bind_param("idddii", $ded_row_id, $ded_amount, $current_bal, $new_bal, $id, $employee_id);
                        $dh->execute();
                        $dh->close();
                    }
                }

                // Update payroll status
                $payroll_update_query = "UPDATE payroll SET status = ? WHERE id = ?";
                $payroll_stmt = $this->db->prepare($payroll_update_query);
                $payroll_stmt->bind_param("si", $status, $id);
                $payroll_stmt->execute();
                $this->save_payroll_history($id, 5, "Lock");
            }

            // Commit Transaction
            $this->db->commit();

            // Locked = payslips are FINAL. Tell every employee in the batch
            // (portal bell + best-effort browser push). Outside the transaction
            // and never fatal — a notification failure must not undo a lock.
            if ((int) $status === 2) {
                try {
                    $p = $this->db->query("SELECT date_from, date_to FROM payroll WHERE id = " . intval($id))->fetch_assoc();
                    $period = date('M j', strtotime($p['date_from'])) . ' – ' . date('M j, Y', strtotime($p['date_to']));
                    $this->notifyEmployeesFromQuery(
                        "SELECT DISTINCT employee_id FROM payroll_items WHERE payroll_id = " . intval($id),
                        'Your final payslip is ready',
                        "Payroll for $period has been finalized. Your payslip is now available in the portal.",
                        'ri-file-text-line',
                        'success',
                        'employee-portal.php?tab=payslips'
                    );
                    require_once __DIR__ . '/fcm.php';
                    $eq = $this->db->query("SELECT DISTINCT employee_id FROM payroll_items WHERE payroll_id = " . intval($id));
                    if ($eq) while ($e = $eq->fetch_assoc()) {
                        fcm_push_employee($this->db, (int) $e['employee_id'], 'Your final payslip is ready', "Payroll for $period has been finalized.", 'employee-portal.php?tab=payslips');
                    }
                } catch (\Throwable $e) { /* best-effort */ }
            }
            return 1;
        } catch (Exception $e) {
            // Rollback on error
            $this->db->rollback();
            return 0;
        }
    }

    // Move a payroll batch into "Ready for Review" (status 3) and notify every employee
    // in it so they can confirm/dispute their own payslip in the portal before it's locked.
    function send_payroll_for_review()
    {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$id) return ['result' => false, 'message' => 'Invalid parameters'];

        $payroll = $this->db->query("SELECT id, date_from, date_to, status FROM payroll WHERE id = $id")->fetch_assoc();
        if (!$payroll) return ['result' => false, 'message' => 'Payroll not found'];
        if ((int)$payroll['status'] === 2) {
            return ['result' => false, 'message' => 'This payroll is already locked.'];
        }
        // Must be calculated first — a New (0) payroll has no items to review.
        if ((int)$payroll['status'] === 0) {
            return ['result' => false, 'message' => 'Calculate this payroll before sending it for review.'];
        }

        $upd = $this->db->query("UPDATE payroll SET status = 3 WHERE id = $id");
        if (!$upd) return ['result' => false, 'message' => $this->db->error];

        // Fresh review round — clear any prior sign-offs for this batch.
        $this->db->query("DELETE FROM payroll_employee_reviews WHERE payroll_id = $id");

        $period = date('M j', strtotime($payroll['date_from'])) . ' – ' . date('M j, Y', strtotime($payroll['date_to']));
        $count = $this->notifyEmployeesFromQuery(
            "SELECT DISTINCT employee_id FROM payroll_items WHERE payroll_id = $id",
            'Payslip ready for your review',
            "Your payslip for $period is ready. Please review and confirm before it's locked.",
            'ri-file-list-3-line',
            'warning',
            'employee-portal.php?tab=payslips'
        );

        $this->save_payroll_history($id, 5, 'Send for Review');

        return ['result' => true, 'message' => "Sent for review. $count employee(s) notified."];
    }

    function loan_history_details()
    {
        $loan_id = $_POST['id'] ?? null;

        if ($loan_id) {
            // Prepare SQL query to fetch records
            $query = "SELECT loan_history.*, payroll.ref_no, payroll.date_from, payroll.date_to FROM loan_history INNER JOIN payroll ON  loan_history.payroll_id = payroll.id WHERE loan_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("i", $loan_id);
            $stmt->execute();
            $result = $stmt->get_result();

            // Fetch data as an associative array
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }

            // Return JSON response
            echo json_encode($data);
        } else {
            echo json_encode(["error" => "Invalid loan_id"]);
        }
    }

    function payroll_history_details()
    {
        $payroll_id = $_POST['id'] ?? null;

        if ($payroll_id) {
            // Prepare SQL query to fetch records
            $query = "SELECT payroll_logs.*, users.name FROM payroll_logs INNER JOIN users ON payroll_logs.user_id = users.id WHERE payroll_id = ? ORDER BY payroll_logs.created_at DESC";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("i", $payroll_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }

            // Return JSON response
            echo json_encode($data);
        } else {
            echo json_encode(["error" => "Invalid payroll_id"]);
        }
    }

    function save_payroll_history($payroll_id, $type = 1, $other = [],  $field2 = null, $value2 = 0)
    {

        $user_id = $_SESSION['login_id'];
        $details = "No Details";
        if ($type === 1) {
            $details = 'New Payroll Created';
        }

        if ($type === 2) {
            $details = 'Payroll Calculated';
        }

        if ($type === 3) {
            $details = 'Payroll Re-calculated';
        }
        if ($type === 4) {
            $employee_id = $other[0]['employee_id'];
            $field = $other[0]['field'];
            $value = $other[0]['value'];
            $query = "SELECT  firstname,lastname  FROM employee WHERE  id = ?";
            $stmt =  $this->db->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $emp = $result->fetch_assoc();
            $files_types = ['present' => 'No. of Days', 'per_day' => 'Basic Rate', 'allowance_amount' => 'Allowance', 'ot' => "Overtime", 'ot_rate' => "Overtime Rate", 'under_time' => "Undertime", "other_deduction" => "Other Deduction", 'late' => 'Late', 'absent' => 'Absent', 'legal_holiday' => 'Legal Holiday', 'sunday_duty' => "Rest Day Duty", "special_holiday" => 'Special Holiday', "sss_fund" => "SSS PROVIDENT FUND", "jei_advances" => "JEI ADVANCE", "jcc_advances" => "JCC ADVANCES", "tax" => "Tax", 'allowance_days' => "Allowance No. dys", 'adjustment' => "Adjustment", 'adjustment_remarks' => "Adjustment Remarks", 'nsd_amount' => "Night Differential"];
            $details = "Employee: " . $emp['lastname'] . ", " . $emp['firstname'] . " & Field: {$files_types[$field]} & Value: $value";
        }

        if ($type === 5) {
            $details = $other . ' Payroll';
        }

        if ($type === 7) {
            $employee_id = $other[0]['employee_id'];
            $field = $other[0]['field'];
            $value = $other[0]['value'];
            $query2 = "SELECT  contribution FROM contributions WHERE  id = ?";
            $stmt2 =  $this->db->prepare($query2);
            $stmt2->bind_param("i", $field2);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $emp22 = $result2->fetch_assoc();


            $query = "SELECT  firstname,lastname  FROM employee WHERE  id = ?";
            $stmt =  $this->db->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $emp = $result->fetch_assoc();

            $details = "Employee: " . $emp['lastname'] . ", " . $emp['firstname'] . " & Field: CONTRIBUTION {$emp22['contribution']} & Value: $value2";
        }

        if ($type === 8) {
            $employee_id = $other[0]['employee_id'];
            $field = $other[0]['field'];
            $value = $other[0]['value'];
            $query2 = "SELECT  deduction FROM deductions WHERE  id = ?";
            $stmt2 =  $this->db->prepare($query2);
            $stmt2->bind_param("i", $field2);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $emp22 = $result2->fetch_assoc();


            $query = "SELECT  firstname,lastname  FROM employee WHERE  id = ?";
            $stmt =  $this->db->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $emp = $result->fetch_assoc();

            $details = "Employee: " . $emp['lastname'] . ", " . $emp['firstname'] . " & Field: DEDUCTION  {$emp22['deduction']} & Value: $value2";
        }

        if ($type === 9) {
            $employee_id = $other[0]['employee_id'];
            $field = $other[0]['field'];
            $value = $other[0]['value'];
            $query2 = "SELECT  loan_type FROM contribution_loan_types WHERE  clt_id = ?";
            $stmt2 =  $this->db->prepare($query2);
            $stmt2->bind_param("i", $field2);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $emp22 = $result2->fetch_assoc();


            $query = "SELECT  firstname,lastname  FROM employee WHERE  id = ?";
            $stmt =  $this->db->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $emp = $result->fetch_assoc();

            $details = "Employee: " . $emp['lastname'] . ", " . $emp['firstname'] . " & Field: {$emp22['loan_type']} & Value: $value2";
        }

        if ($type === 10) {
            $employee_id = $other[0]['employee_id'];
            $field = $other[0]['field'];
            $value = $other[0]['value'];
            $query2 = "SELECT  refunds FROM refunds WHERE  id = ?";
            $stmt2 =  $this->db->prepare($query2);
            $stmt2->bind_param("i", $field2);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $emp22 = $result2->fetch_assoc();


            $query = "SELECT  firstname,lastname  FROM employee WHERE  id = ?";
            $stmt =  $this->db->prepare($query);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $emp = $result->fetch_assoc();

            $details = "Employee: " . $emp['lastname'] . ", " . $emp['firstname'] . " & Field: REFUND {$emp22['refunds']} & Value: $value2";
        }





        $data = " payroll_id='$payroll_id' ";
        $data .= ", user_id = '$user_id' ";
        $data .= ", details = '$details' ";
        // var_dump("INSERT INTO payroll_logs set " . $data);
        $save = $this->db->query("INSERT INTO payroll_logs set " . $data);
    }

    function update_payroll_print()
    {
        if (isset($_POST['id'], $_POST['prepared_by'], $_POST['prepared_by_role'], $_POST['verified_by'], $_POST['verified_by_role'], $_POST['approved_by'], $_POST['approved_by_role'])) {
            $id = $_POST['id'];
            $prepared_by = $_POST['prepared_by'];
            $prepared_by_role = $_POST['prepared_by_role'];
            $verified_by = $_POST['verified_by'];
            $verified_by_role = $_POST['verified_by_role'];
            $approved_by = $_POST['approved_by'];
            $approved_by_role = $_POST['approved_by_role'];

            $stmt = $this->db->prepare("UPDATE payroll SET prepared_by = ?, prepared_by_role = ?, verified_by = ?, verified_by_role = ?, approved_by = ?, approved_by_role = ? WHERE id = ?");

            if ($stmt) {
                $stmt->bind_param('ssssssi', $prepared_by, $prepared_by_role, $verified_by, $verified_by_role, $approved_by, $approved_by_role, $id);

                if ($stmt->execute()) {
                    return ['result' => true, 'message' => 'updated'];
                } else {
                    return ['result' => false, 'message' => $stmt->error];
                }
            } else {
                return ['result' => false, 'message' => 'Statement preparation failed'];
            }
        } else {
            return ['result' => false, 'message' => 'Missing required fields'];
        }
    }

    function save_refunds()
    {
        extract($_POST);

        $data = " refunds='$refunds' ";

        // $data .= ", department_id = '$department_id' ";

        if (empty($id)) {
            $this->db->query("INSERT INTO refunds set " . $data);
            return 1;
        } else {
            $this->db->query("UPDATE refunds set " . $data . " where id=" . $id);
            return 2;
        }
    }

    /* ──────────────────────────────────────────────────────────────
     * Leave management
     * ────────────────────────────────────────────────────────────── */

    // Create / update a leave type (Sick, Vacation, etc.) with its annual credit.
    function save_leave_type()
    {
        $id           = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name         = trim($_POST['name'] ?? '');
        $days_allowed = (int) ($_POST['days_allowed'] ?? 0);
        $is_paid      = isset($_POST['is_paid']) ? (int) $_POST['is_paid'] : 1;
        $description  = trim($_POST['description'] ?? '');
        $status       = isset($_POST['status']) ? (int) $_POST['status'] : 1;
        // Year-end policy (paid types only): carry unused credits, with optional cap.
        $carryover    = ($is_paid === 1 && (int) ($_POST['carryover'] ?? 0) === 1) ? 1 : 0;
        $cap_raw      = trim($_POST['carryover_cap'] ?? '');
        $carry_cap    = ($carryover === 1 && $cap_raw !== '') ? max(0.0, (float) $cap_raw) : null;
        // No balance limit (paid types only): filing is never blocked by an
        // insufficient or unset balance — days are still tracked and reported.
        $no_limit     = ($is_paid === 1 && (int) ($_POST['no_limit'] ?? 0) === 1) ? 1 : 0;

        if ($name === '') {
            return ['result' => false, 'message' => 'Leave type name is required.'];
        }
        if ($days_allowed < 0) {
            return ['result' => false, 'message' => 'Days allowed cannot be negative.'];
        }

        if ($id === 0) {
            $stmt = $this->db->prepare("INSERT INTO leave_types (name, days_allowed, is_paid, description, status, carryover, carryover_cap, no_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('siisiidi', $name, $days_allowed, $is_paid, $description, $status, $carryover, $carry_cap, $no_limit);
        } else {
            $stmt = $this->db->prepare("UPDATE leave_types SET name = ?, days_allowed = ?, is_paid = ?, description = ?, status = ?, carryover = ?, carryover_cap = ?, no_limit = ? WHERE id = ?");
            $stmt->bind_param('siisiidii', $name, $days_allowed, $is_paid, $description, $status, $carryover, $carry_cap, $no_limit, $id);
        }

        if ($stmt->execute()) {
            return ['result' => true, 'message' => $id === 0 ? 'Leave type added.' : 'Leave type updated.'];
        }
        return ['result' => false, 'message' => $stmt->error];
    }

    function delete_leave_type()
    {
        $id = (int) ($_POST['id'] ?? 0);
        // Block deletion if the type is already used by a request.
        $used = $this->db->query("SELECT COUNT(*) AS c FROM leave_requests WHERE leave_type_id = $id")->fetch_assoc();
        if ($used && (int) $used['c'] > 0) {
            return ['result' => false, 'message' => 'Cannot delete: this leave type is already used by leave requests.'];
        }
        if ($this->db->query("DELETE FROM leave_types WHERE id = $id")) {
            return ['result' => true, 'message' => 'Leave type deleted.'];
        }
        return ['result' => false, 'message' => $this->db->error];
    }

    // File a leave request (or edit a pending one).
    // Normalize a submitted set of leave days into a clean, sorted Y-m-d list.
    // Accepts POST 'dates' (comma/space separated) or a date_from..date_to range.
    private function collectLeaveDates($raw_dates, $date_from, $date_to)
    {
        $days = [];
        if (trim((string) $raw_dates) !== '') {
            foreach (preg_split('/[,\s]+/', trim($raw_dates)) as $d) {
                $ts = strtotime($d);
                if ($ts !== false) $days[date('Y-m-d', $ts)] = true;
            }
        } elseif ($date_from !== '' && $date_to !== '') {
            $ts_f = strtotime($date_from);
            $ts_t = strtotime($date_to);
            if ($ts_f !== false && $ts_t !== false && $ts_t >= $ts_f) {
                for ($d = $ts_f; $d <= $ts_t; $d = strtotime('+1 day', $d)) $days[date('Y-m-d', $d)] = true;
            }
        }
        $days = array_keys($days);
        sort($days);
        return $days;
    }

    function save_leave_request()
    {
        $id            = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $employee_id   = (int) ($_POST['employee_id'] ?? 0);
        $leave_type_id = (int) ($_POST['leave_type_id'] ?? 0);
        $date_from     = trim($_POST['date_from'] ?? '');
        $date_to       = trim($_POST['date_to'] ?? '');
        $reason        = trim($_POST['reason'] ?? '');
        $date_applied  = trim($_POST['date_applied'] ?? '') ?: date('Y-m-d');
        $filed_by      = $_SESSION['login_id'] ?? null;

        if ($employee_id <= 0)   return ['result' => false, 'message' => 'Please select an employee.'];
        if ($leave_type_id <= 0) return ['result' => false, 'message' => 'Please select a leave type.'];

        // Scoped Department Heads may only file for their own department's employees.
        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = $employee_id" . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This employee belongs to another department.'];
        }

        $days = $this->collectLeaveDates($_POST['dates'] ?? '', $date_from, $date_to);
        if (count($days) === 0) return ['result' => false, 'message' => 'Please select at least one leave date.'];

        // Reject any day that falls on a leave-blocking holiday.
        $blocked = $this->getBlockedDates();
        $hit = array_intersect($days, array_keys($blocked));
        if (count($hit)) {
            $names = [];
            foreach ($hit as $d) $names[] = date('M d', strtotime($d)) . ' (' . $blocked[$d] . ')';
            return ['result' => false, 'message' => 'Leave not allowed on: ' . implode(', ', $names) . '.'];
        }

        // Duplicate-date guard: reject days already covered by another PENDING or
        // APPROVED request of this employee. Rejected requests don't block; when
        // editing, the request being edited is excluded from the check.
        $taken = [];
        $dupq  = $this->db->query("SELECT dates, date_from, date_to FROM leave_requests
            WHERE employee_id = $employee_id AND status IN (0,1)" . ($id > 0 ? " AND id <> $id" : ''));
        if ($dupq) while ($dx = $dupq->fetch_assoc()) {
            $dd = [];
            if (!empty($dx['dates'])) { $j = json_decode($dx['dates'], true); if (is_array($j)) $dd = $j; }
            if (!$dd) { for ($t = strtotime($dx['date_from']); $t <= strtotime($dx['date_to']); $t = strtotime('+1 day', $t)) $dd[] = date('Y-m-d', $t); }
            foreach ($dd as $d1) $taken[date('Y-m-d', strtotime($d1))] = true;
        }
        $dup_hit = array_values(array_filter($days, function ($d1) use ($taken) { return isset($taken[date('Y-m-d', strtotime($d1))]); }));
        if ($dup_hit) {
            $nice = array_map(function ($d1) { return date('M d', strtotime($d1)); }, array_slice($dup_hit, 0, 5));
            return ['result' => false, 'message' => 'This employee already has a pending or approved leave on: '
                . implode(', ', $nice) . (count($dup_hit) > 5 ? '…' : '') . '.'];
        }

        // Balance guard (paid leave only — LWOP consumes no credits): the same
        // rule the employee portal enforces on self-filing, so an HR/admin
        // filing can't overbook an employee past their credits and drive the
        // portal balance negative. Remaining counts approved AND still-pending
        // requests; when editing, the request being edited is excluded.
        // Credits with no explicit HR-set row for the year default to 0 (not
        // the type's days_allowed) — an employee isn't entitled to a balance
        // until HR sets one. `no_limit` types (e.g. Sick Leave) skip this guard
        // entirely so filing is never blocked by a missing/zero balance.
        $lt_row = $this->db->query("SELECT is_paid, no_limit FROM leave_types WHERE id = $leave_type_id LIMIT 1")->fetch_assoc();
        if ($lt_row && (int) $lt_row['is_paid'] === 1 && (int) $lt_row['no_limit'] === 0) {
            $ly = leave_current_year();
            $balq = $this->db->query("
                SELECT COALESCE(c.credits, 0) - COALESCE(u.used, 0) AS remaining
                FROM leave_types lt
                LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $employee_id AND c.year = $ly
                LEFT JOIN (
                    SELECT leave_type_id, SUM(duration) AS used
                    FROM leave_requests
                    WHERE employee_id = $employee_id AND status IN (0,1) AND YEAR(date_from) = $ly"
                    . ($id > 0 ? " AND id <> $id" : '') . "
                    GROUP BY leave_type_id
                ) u ON u.leave_type_id = lt.id
                WHERE lt.id = $leave_type_id");
            $remaining = $balq ? (float) ($balq->fetch_assoc()['remaining'] ?? 0) : 0.0;
            $need = (float) count($days);
            if ($need > $remaining + 0.001) {
                $fmtd = function ($v) { return rtrim(rtrim(number_format((float) $v, 1), '0'), '.'); };
                return ['result' => false, 'message' =>
                    'Not enough leave credits — this request needs ' . $fmtd($need) . ' day(s) but the employee only has '
                    . $fmtd(max(0, $remaining)) . ' left for this leave type (pending requests included).'];
            }
        }

        $duration   = count($days);
        $date_from  = $days[0];
        $date_to    = $days[count($days) - 1];
        $dates_json = json_encode($days);
        $date_applied = date('Y-m-d', strtotime($date_applied));

        if ($id === 0) {
            $stmt = $this->db->prepare("INSERT INTO leave_requests (employee_id, leave_type_id, date_applied, date_from, date_to, duration, dates, reason, filed_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->bind_param('iisssdssi', $employee_id, $leave_type_id, $date_applied, $date_from, $date_to, $duration, $dates_json, $reason, $filed_by);
        } else {
            // Only fully-pending requests (no decision yet) can be edited.
            $stmt = $this->db->prepare("UPDATE leave_requests SET employee_id = ?, leave_type_id = ?, date_from = ?, date_to = ?, duration = ?, dates = ?, reason = ? WHERE id = ? AND hr_status = 0 AND admin_status = 0");
            $stmt->bind_param('iissdssi', $employee_id, $leave_type_id, $date_from, $date_to, $duration, $dates_json, $reason, $id);
        }

        if ($stmt->execute()) {
            $skipped = [];
            if ($id === 0) {
                $new_id = $this->db->insert_id;
                // Resolve optional stages with no approver in this department
                // BEFORE notifying, so the alert goes to whoever is actually next.
                $skipped = leave_autoskip_stages($this->db, (int) $new_id, $employee_id);
                $info = $this->leaveInfo($new_id);
                [$firstKey, $firstCfg] = leave_first_open_stage($this->db, (int) $new_id);
                if ($firstCfg) {
                    $this->notifyRoleForEmployee((int) $firstCfg['role'], $employee_id, 'New leave request', "{$info['emp']} filed a {$info['type']} ({$info['dur']} day/s). Needs {$firstCfg['label']} approval.", $firstCfg['icon'] ?? 'ri-calendar-event-line', 'warning', 'index.php?page=leaves');
                }
            }
            // Non-blocking: overlapping attendance is legitimate (half-day, or
            // leave taken mid-shift), so the filer is told rather than stopped.
            $msg = $id === 0 ? 'Leave request filed. Sent to HR for review.' : 'Leave request updated.';
            if ($skipped) $msg .= ' Skipped: ' . implode(', ', $skipped) . ' (none assigned to this department).';
            return ['result' => true, 'message' => $msg . leave_attendance_note($this->db, $employee_id, $days)];
        }
        return ['result' => false, 'message' => $stmt->error];
    }

    // Filing helper for the admin/HR File Leave modal: the selected employee's
    // remaining credits per leave type plus the dates already covered by their
    // pending/approved requests, so the picker can disable them up front and
    // the form can show a live balance — the same behavior the employee portal
    // has. exclude_id skips the request currently being edited.
    function get_leave_filing_info()
    {
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $exclude_id  = (int) ($_POST['exclude_id'] ?? 0);
        if ($employee_id <= 0) return ['result' => false, 'message' => 'No employee selected.'];
        $excl = $exclude_id > 0 ? " AND id <> $exclude_id" : '';
        $ly = leave_current_year();
        $remain = [];
        $rq = $this->db->query("
            SELECT lt.id, lt.is_paid, lt.no_limit,
                   COALESCE(c.credits, 0) - COALESCE(u.used, 0) AS remaining
            FROM leave_types lt
            LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $employee_id AND c.year = $ly
            LEFT JOIN (
                SELECT leave_type_id, SUM(duration) AS used
                FROM leave_requests
                WHERE employee_id = $employee_id AND status IN (0,1) AND YEAR(date_from) = $ly $excl
                GROUP BY leave_type_id
            ) u ON u.leave_type_id = lt.id
            WHERE lt.status = 1");
        if ($rq) while ($r = $rq->fetch_assoc()) {
            // Unpaid (LWOP) and no_limit (e.g. Sick Leave) types have no hard
            // cap to show — null means "don't block on balance".
            $capped = ((int) $r['is_paid'] === 1) && ((int) $r['no_limit'] === 0);
            $remain[(int) $r['id']] = $capped ? (float) $r['remaining'] : null;
        }
        $taken = [];
        $tq = $this->db->query("SELECT dates, date_from, date_to FROM leave_requests
            WHERE employee_id = $employee_id AND status IN (0,1) $excl");
        if ($tq) while ($t = $tq->fetch_assoc()) {
            $dd = [];
            if (!empty($t['dates'])) { $j = json_decode($t['dates'], true); if (is_array($j)) $dd = $j; }
            if (!$dd) { for ($s = strtotime($t['date_from']); $s <= strtotime($t['date_to']); $s = strtotime('+1 day', $s)) $dd[] = date('Y-m-d', $s); }
            foreach ($dd as $d1) $taken[date('Y-m-d', strtotime($d1))] = true;
        }
        $taken = array_keys($taken);
        sort($taken);
        return ['result' => true, 'remain' => $remain, 'taken' => $taken];
    }

    // Config-driven approval decision. `stage` is a key in LEAVE_APPROVAL_STAGES
    // (db_connect.php); the current flow is sup → admin → hr. status = 1 approve,
    // 2 reject. Ordering, allowed role, and notifications all follow the config.
    function decide_leave()
    {
        $id      = (int) ($_POST['id'] ?? 0);
        $stage   = $_POST['stage'] ?? '';
        $status  = (int) ($_POST['status'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        $uid     = $_SESSION['login_id'] ?? null;
        $role    = (int) ($_SESSION['login_role'] ?? 0);

        if ($id <= 0) return ['result' => false, 'message' => 'Invalid request.'];
        if (!in_array($status, [0, 1, 2], true)) return ['result' => false, 'message' => 'Invalid status.'];

        $row = $this->db->query("SELECT * FROM leave_requests WHERE id = $id")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Leave request not found.'];

        // Scoped Department Heads may only act on their own department's requests.
        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = " . (int)$row['employee_id'] . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This request belongs to another department.'];
        }

        // Validate the stage against the configured workflow (LEAVE_APPROVAL_STAGES).
        $stages = leave_stages();
        if (!isset($stages[$stage])) return ['result' => false, 'message' => 'Invalid approval stage.'];
        $cfg = $stages[$stage];

        // TWO checks, doing two different jobs.
        //
        // 1) Coarse family gate: must hold SOME approver role at all. This is
        //    defense in depth against a session that should never have reached
        //    here — decide_leave has no entry in ACTION_PAGE_MAP, so this
        //    function is the only thing standing between a signed-in Timekeeper
        //    and this endpoint.
        //
        // 2) leave_user_can_act(): must be THE approver for THIS employee's area
        //    at THIS stage. This replaced a plain `$role !== $cfg['role']`
        //    equality check, which broke the moment a person holds two stages —
        //    e.g. BACONGUIS is Section Head (role 11's stage) of HEAD NURSE and
        //    Supervisor (role 10's stage) of three wards, but users.role can
        //    only ever hold one value. Equality against the stage's configured
        //    role rejected him outright on whichever stage did not match it,
        //    which meant those requests could never be decided by anyone.
        //    leave_user_can_act reads area_approver directly instead of
        //    users.role, so it is correct regardless of how many stages one
        //    person holds.
        if (!in_array($role, [8, 9, 10, 11], true)) {
            return ['result' => false, 'message' => 'You are not allowed to act on the ' . $cfg['label'] . ' approval.'];
        }
        if (!leave_user_can_act($this->db, (int) $uid, $stage, (int) $row['employee_id'])) {
            return ['result' => false, 'message' => 'You are not the ' . $cfg['label'] . ' for this employee\'s area.'];
        }

        // Enforce the chain order: a stage can only be decided while it is the one
        // currently awaiting action — i.e. all earlier stages approved, none
        // rejected, and this stage not already decided.
        if (leave_current_stage($row) !== $stage) {
            if ((int) ($row[$stage . '_status'] ?? 0) !== 0) {
                return ['result' => false, 'message' => 'This stage has already been decided.'];
            }
            return ['result' => false, 'message' => 'An earlier approval is still required before the ' . $cfg['label'] . ' stage.'];
        }

        if ($status === 2 && $remarks === '') {
            return ['result' => false, 'message' => 'A reason is required to reject.'];
        }

        $remarks_sql = $remarks !== '' ? "'" . $this->db->real_escape_string($remarks) . "'" : 'NULL';
        $uid_sql     = $uid ? (int) $uid : 'NULL';

        // Persist this stage's decision ({stage}_status / _by / _remarks / _at).
        $this->db->query(
            "UPDATE leave_requests
             SET {$stage}_status = $status, {$stage}_by = $uid_sql,
                 {$stage}_remarks = $remarks_sql, {$stage}_at = NOW()
             WHERE id = $id"
        );

        // Recompute the overall status from every configured stage.
        $r2       = $this->db->query("SELECT * FROM leave_requests WHERE id = $id")->fetch_assoc();
        $overall  = leave_overall_status($r2);
        $appr_sql = ($overall === 1) ? $uid_sql : 'NULL';
        $this->db->query("UPDATE leave_requests SET status = $overall, approved_by = $appr_sql WHERE id = $id");

        // Fire notifications.
        $info     = $this->leaveInfo($id);
        $link     = 'index.php?page=leaves';             // staff review page
        $emp_link = 'employee-portal.php?tab=leave';     // employee self-service portal
        $emp      = (int) $row['employee_id'];           // the leave owner
        $stageLbl = $cfg['label'];
        $hr_stage = leave_stage_for_role(9);             // HR owns the leave records

        if ($status === 2) {
            // Rejected — halts the chain. Tell the employee, and HR (unless HR did it).
            $this->notifyEmployee($emp, 'Leave rejected', "Your {$info['type']} was rejected by {$stageLbl}." . ($remarks ? " Reason: $remarks" : ''), 'ri-close-circle-line', 'danger', $emp_link);
            if ($hr_stage !== $stage) {
                $this->notifyRole(9, 'Leave rejected', "{$info['emp']}'s {$info['type']} was rejected by {$stageLbl}.", 'ri-close-circle-line', 'danger', $link);
            }
        } elseif ($status === 1) {
            $next = leave_current_stage($r2);            // next stage awaiting action, or null when done
            if ($next === null) {
                // Fully approved through the whole chain.
                $this->notifyEmployee($emp, 'Leave fully approved', "Your {$info['type']} has been fully approved.", 'ri-checkbox-circle-line', 'success', $emp_link);
                $this->notifyRole(9, 'Leave fully approved', "{$info['emp']}'s {$info['type']} received final approval.", 'ri-checkbox-circle-line', 'success', $link);
            } else {
                // Hand off to the next approver (scoped to the employee's department).
                $nextCfg = $stages[$next];
                $this->notifyRoleForEmployee((int) $nextCfg['role'], $emp, 'Leave needs your approval', "{$info['emp']}'s {$info['type']} ({$info['dur']} day/s) is awaiting {$nextCfg['label']} approval.", $nextCfg['icon'] ?? 'ri-shield-check-line', 'info', $link);
                $this->notifyEmployee($emp, "Leave approved by {$stageLbl}", "Your {$info['type']} was approved by {$stageLbl}. Awaiting {$nextCfg['label']} approval.", 'ri-checkbox-circle-line', 'info', $emp_link);
            }
        }

        $label = $status === 1 ? 'approved' : ($status === 2 ? 'rejected' : 'updated');
        return ['result' => true, 'message' => "{$stageLbl} stage $label."];
    }

    function delete_leave_request()
    {
        $id   = (int) ($_POST['id'] ?? 0);
        $role = (int) ($_SESSION['login_role'] ?? 0);
        // Deleting is HR (9) / Administrator (1) only. The approvers in the chain
        // (Section Head 11, Supervisor 10, Dept Head 8) reject instead — the same
        // list gates the Delete button in leaves.php ($can_delete_leave).
        if (!in_array($role, [1, 9], true)) {
            return ['result' => false, 'message' => 'Only HR and Administrators may delete leave requests.'];
        }
        $row = $this->db->query("SELECT * FROM leave_requests WHERE id = $id")->fetch_assoc();
        if (!$row) return ['result' => false, 'message' => 'Leave request not found.'];

        // Dept-scoped users (Head / Supervisor) may only act within their department.
        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = " . (int) $row['employee_id'] . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This request belongs to another department.'];
        }

        // An APPROVED leave already counts toward balances and payroll — deleting
        // it would silently rewrite history. It must be rejected instead.
        if ((int) $row['status'] === 1) {
            return ['result' => false, 'message' => 'This leave is already approved and counted in balances/payroll. Reject it instead of deleting.'];
        }

        if ($this->db->query("DELETE FROM leave_requests WHERE id = $id")) {
            return ['result' => true, 'message' => 'Leave request deleted.'];
        }
        return ['result' => false, 'message' => $this->db->error];
    }

    // HR: change an employee's leave credits for a leave type.
    // mode = 'set' (absolute), 'add' (+amount) or 'deduct' (−amount, floored at 0).
    // add/deduct require a reason; every change is written to leave_credit_history.
    function save_leave_credit()
    {
        $employee_id   = (int) ($_POST['employee_id'] ?? 0);
        $leave_type_id = (int) ($_POST['leave_type_id'] ?? 0);
        $mode          = $_POST['mode'] ?? 'set';
        if (!in_array($mode, ['set', 'add', 'deduct'], true)) $mode = 'set';
        // 'amount' = delta for add/deduct or the absolute for set; falls back to the
        // legacy 'credits' field so the plain SET editor keeps working unchanged.
        $amount        = (float) ($_POST['amount'] ?? $_POST['credits'] ?? 0);
        $reason        = trim($_POST['reason'] ?? '');

        // Server-side guard matching the UI: only HR may change credits
        // (LEAVE_CREDIT_EDIT_ROLES); scoped users only within their own department.
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!can_edit_leave_credits($role)) {
            return ['result' => false, 'message' => 'Only HR can change leave credits.'];
        }
        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = $employee_id" . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This employee belongs to another department.'];
        }

        if ($employee_id <= 0 || $leave_type_id <= 0) {
            return ['result' => false, 'message' => 'Invalid employee or leave type.'];
        }
        if ($amount < 0) {
            return ['result' => false, 'message' => 'Amount cannot be negative.'];
        }
        if ($mode !== 'set' && $amount <= 0) {
            return ['result' => false, 'message' => 'Enter an amount greater than zero.'];
        }
        if ($mode !== 'set' && $reason === '') {
            return ['result' => false, 'message' => 'A reason is required to add or deduct credits.'];
        }

        // Only Regular / Executive (or overridden) employees are entitled to leave credits.
        if (!$this->isLeaveEligible($employee_id)) {
            return ['result' => false, 'message' => 'This employee is not eligible for leave credits.'];
        }

        $changer = $_SESSION['login_id'] ?? null;
        $year    = leave_current_year();

        // Current value for this year (0 when HR hasn't set one yet).
        $cur = $this->db->query("
            SELECT COALESCE(c.credits, 0) AS credits
            FROM leave_types lt
            LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $employee_id AND c.year = $year
            WHERE lt.id = $leave_type_id
        ")->fetch_assoc();
        $old_credits = $cur ? (float) $cur['credits'] : 0;

        // Resolve the new balance from the requested action.
        if ($mode === 'add')        $new_credits = $old_credits + $amount;
        elseif ($mode === 'deduct') $new_credits = max(0.0, $old_credits - $amount);
        else                        $new_credits = $amount;   // set

        // Upsert on the (employee_id, leave_type_id, year) unique key.
        $stmt = $this->db->prepare("
            INSERT INTO employee_leave_credits (employee_id, leave_type_id, year, credits)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE credits = VALUES(credits)
        ");
        $stmt->bind_param('iiid', $employee_id, $leave_type_id, $year, $new_credits);
        if (!$stmt->execute()) {
            return ['result' => false, 'message' => $stmt->error];
        }

        // Record history + notify when the value actually changed.
        if ((float) $old_credits !== (float) $new_credits) {
            $cb_sql     = $changer ? (int) $changer : 'NULL';
            $reason_sql = $reason !== '' ? "'" . $this->db->real_escape_string($reason) . "'" : 'NULL';
            $type_sql   = "'" . $this->db->real_escape_string($mode) . "'";
            $this->db->query("INSERT INTO leave_credit_history (employee_id, leave_type_id, old_credits, new_credits, change_type, reason, changed_by)
                VALUES ($employee_id, $leave_type_id, $old_credits, $new_credits, $type_sql, $reason_sql, $cb_sql)");

            // Build a readable message.
            $meta = $this->db->query("
                SELECT lt.name AS type
                FROM leave_types lt WHERE lt.id = $leave_type_id
            ")->fetch_assoc();
            $type = $meta['type'] ?? 'leave';
            $who  = 'Someone';
            if ($changer) {
                $wq = $this->db->query("SELECT name FROM users WHERE id = " . (int) $changer)->fetch_assoc();
                $who = $wq['name'] ?? 'A user';
            }
            $fmt  = fn($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
            $verb = $mode === 'add' ? 'added ' . $fmt($amount) . ' day(s) to' : ($mode === 'deduct' ? 'deducted ' . $fmt($amount) . ' day(s) from' : 'updated');
            $emp_msg = "$who $verb your $type balance: " . $fmt($old_credits) . " → " . $fmt($new_credits) . " day(s)."
                     . ($reason !== '' ? " Reason: $reason" : '');

            // Notify only the employee whose balance changed — portal bell + FCM
            // push — so they see it even with the site closed. HR/Admin are the
            // ones making the change, so they don't need a self-notification;
            // the leave_credit_history + Balance Change History panel already
            // cover the audit trail for reviewers.
            $this->notifyEmployee($employee_id, 'Leave balance updated', $emp_msg, 'ri-coins-line', 'info', 'employee-portal.php?tab=leave');
        }

        $labels = ['set' => 'Balance updated', 'add' => 'Credits added', 'deduct' => 'Credits deducted'];
        return ['result' => true, 'message' => $labels[$mode] . '.'];
    }

    // HR only: set the per-employee leave eligibility override.
    // override = '' | 'auto' (NULL, follow classification) · '1' (force allow) · '0' (force block).
    function save_leave_override()
    {
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!can_edit_leave_credits($role)) {
            return ['result' => false, 'message' => 'Only HR can change leave eligibility.'];
        }
        $employee_id = (int) ($_POST['employee_id'] ?? 0);
        $val         = $_POST['override'] ?? '';
        if ($employee_id <= 0) return ['result' => false, 'message' => 'Invalid employee.'];

        // Department Heads may only act within their own department.
        require_once __DIR__ . '/dept-scope.php';
        if (dept_scope_id() > 0) {
            $chk = $this->db->query("SELECT id FROM employee WHERE id = $employee_id" . dept_scope_sql('department_id'))->fetch_assoc();
            if (!$chk) return ['result' => false, 'message' => 'This employee belongs to another department.'];
        }

        if ($val === '' || $val === 'auto') {
            $set = 'NULL';        $label = 'Auto (by classification)';
        } elseif ($val === '1') {
            $set = '1';           $label = 'Always allowed';
        } elseif ($val === '0') {
            $set = '0';           $label = 'Always blocked';
        } else {
            return ['result' => false, 'message' => 'Invalid override value.'];
        }
        $this->db->query("UPDATE employee SET leave_override = $set WHERE id = $employee_id");

        return ['result' => true, 'message' => 'Leave eligibility set to: ' . $label . '.'];
    }

    // Year-end rollover: seed each eligible employee's TARGET-year balance from the
    // SOURCE year, following every paid leave type's policy (reset vs carry-over,
    // with an optional cap). mode = 'preview' (dry run, no writes) or 'run'.
    // Every applied change is logged to leave_credit_history for audit.
    function run_leave_rollover()
    {
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!can_run_leave_rollover($role)) {   // LEAVE_ROLLOVER_ROLES
            return ['result' => false, 'message' => 'Only Admin/HR can run the year-end rollover.'];
        }
        $from_year = (int) ($_POST['from_year'] ?? 0);
        $to_year   = (int) ($_POST['to_year'] ?? ($from_year + 1));
        $preview   = (($_POST['mode'] ?? 'preview') !== 'run');
        $changer   = $_SESSION['login_id'] ?? null;

        if ($from_year < 2000 || $to_year <= $from_year) {
            return ['result' => false, 'message' => 'Invalid year range.'];
        }

        // Active employees + the data needed to judge leave eligibility.
        $emps = $this->db->query("
            SELECT e.id, CONCAT(e.lastname, ', ', e.firstname) AS name,
                   UPPER(COALESCE(cl.clasification,'')) AS clasif, e.leave_override
            FROM employee e
            LEFT JOIN clasification cl ON cl.id = e.clasification_id
            WHERE e.status = 1
            ORDER BY e.lastname ASC
        ");
        if (!$emps) return ['result' => false, 'message' => 'Could not read employees.'];

        // Paid leave types + their year-end policy.
        $types = [];
        $tq = $this->db->query("SELECT id, name, days_allowed, carryover, carryover_cap FROM leave_types WHERE status = 1 AND is_paid = 1 ORDER BY name ASC");
        if ($tq) while ($t = $tq->fetch_assoc()) $types[] = $t;
        if (!$types) return ['result' => false, 'message' => 'No paid leave types configured.'];

        $rows = []; $emp_count = 0; $changed = 0;

        while ($e = $emps->fetch_assoc()) {
            if (!leave_eligibility_from($e['clasif'], $e['leave_override'])) continue;
            $emp_count++;
            $eid = (int) $e['id'];

            foreach ($types as $t) {
                $tid       = (int) $t['id'];
                $allowance = (float) $t['days_allowed'];

                // Source-year balance (0 if HR never set one that year).
                $src = $this->db->query("SELECT credits FROM employee_leave_credits WHERE employee_id=$eid AND leave_type_id=$tid AND year=$from_year")->fetch_assoc();
                $src_credits = $src ? (float) $src['credits'] : 0.0;

                // Days used in the source year.
                $u = $this->db->query("SELECT COALESCE(SUM(duration),0) AS used FROM leave_requests WHERE employee_id=$eid AND leave_type_id=$tid AND status=1 AND YEAR(date_from)=$from_year")->fetch_assoc();
                $used     = (float) $u['used'];
                $leftover = max(0.0, $src_credits - $used);

                // Target-year starting balance from the policy.
                if ((int) $t['carryover'] === 1) {
                    $carried = $leftover;
                    if ($t['carryover_cap'] !== null) $carried = min($carried, (float) $t['carryover_cap']);
                    $new_credits = $allowance + $carried;
                } else {
                    $carried = 0.0;
                    $new_credits = $allowance;
                }

                // Existing target value (for the old→new audit line; 0 if unset).
                $tgt = $this->db->query("SELECT credits FROM employee_leave_credits WHERE employee_id=$eid AND leave_type_id=$tid AND year=$to_year")->fetch_assoc();
                $old_target = $tgt ? (float) $tgt['credits'] : 0.0;

                if (count($rows) < 500) {   // cap the preview payload; counts below are exact
                    $rows[] = [
                        'employee' => $e['name'], 'type' => $t['name'],
                        'policy'   => ((int) $t['carryover'] === 1 ? 'carry' : 'reset'),
                        'leftover' => $leftover, 'carried' => $carried,
                        'old'      => $old_target, 'new' => $new_credits,
                    ];
                }

                if (!$preview) {
                    $ins = $this->db->prepare("INSERT INTO employee_leave_credits (employee_id, leave_type_id, year, credits) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE credits = VALUES(credits)");
                    $ins->bind_param('iiid', $eid, $tid, $to_year, $new_credits);
                    $ins->execute();
                    if ((float) $old_target !== (float) $new_credits) {
                        $reason_sql = "'" . $this->db->real_escape_string("Year-end rollover {$from_year}→{$to_year}") . "'";
                        $cb = $changer ? (int) $changer : 'NULL';
                        $this->db->query("INSERT INTO leave_credit_history (employee_id, leave_type_id, old_credits, new_credits, change_type, reason, changed_by)
                            VALUES ($eid, $tid, $old_target, $new_credits, 'set', $reason_sql, $cb)");
                        $changed++;
                    }
                }
            }
        }

        if ($preview) {
            return ['result' => true, 'preview' => true, 'from_year' => $from_year, 'to_year' => $to_year,
                    'employees' => $emp_count, 'rows' => $rows, 'truncated' => (count($rows) >= 500)];
        }
        return ['result' => true, 'preview' => false, 'from_year' => $from_year, 'to_year' => $to_year,
                'employees' => $emp_count, 'changed' => $changed,
                'message' => "Rollover {$from_year}→{$to_year} complete: $emp_count employee(s), $changed balance change(s)."];
    }

    // Seed missing employee_leave_credits rows to each leave type's reference
    // days_allowed for a given year — a bulk companion to save_leave_credit()
    // now that unset credits default to 0 (blocking filing). ONLY fills gaps:
    // an employee/type that already has a row for the year is left untouched,
    // so this is safe to run repeatedly (e.g. after adding a new hire).
    // Every seeded row is logged to leave_credit_history like a manual "set".
    function bulk_init_leave_credits()
    {
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!can_edit_leave_credits($role)) {
            return ['result' => false, 'message' => 'Only Admin/HR can initialize leave credits.'];
        }
        require_once __DIR__ . '/dept-scope.php';
        $year = leave_current_year();

        $types = [];
        $tq = $this->db->query("SELECT id, days_allowed FROM leave_types WHERE status = 1 AND is_paid = 1");
        if ($tq) while ($t = $tq->fetch_assoc()) $types[(int) $t['id']] = (float) $t['days_allowed'];
        if (!$types) return ['result' => false, 'message' => 'No paid leave types configured.'];

        $emps = $this->db->query("
            SELECT e.id, UPPER(COALESCE(cl.clasification,'')) AS clasif, e.leave_override
            FROM employee e
            LEFT JOIN clasification cl ON cl.id = e.clasification_id
            WHERE e.status = 1" . dept_scope_sql('e.department_id')
        );
        if (!$emps) return ['result' => false, 'message' => 'Could not read employees.'];

        $eligible_ids = [];
        while ($e = $emps->fetch_assoc()) {
            if (leave_eligibility_from($e['clasif'], $e['leave_override'])) $eligible_ids[] = (int) $e['id'];
        }
        if (!$eligible_ids) return ['result' => true, 'seeded' => 0, 'message' => 'No eligible employees to initialize.'];

        // Existing rows for the year — skip anything already set.
        $ids_sql = implode(',', $eligible_ids);
        $have = [];
        $hq = $this->db->query("SELECT employee_id, leave_type_id FROM employee_leave_credits
                                 WHERE year = $year AND employee_id IN ($ids_sql)");
        if ($hq) while ($h = $hq->fetch_assoc()) $have[(int) $h['employee_id']][(int) $h['leave_type_id']] = true;

        $changer  = $_SESSION['login_id'] ?? null;
        $cb_sql   = $changer ? (int) $changer : 'NULL';
        $reason   = $this->db->real_escape_string('Bulk initialized to leave type default');
        $cred_ins = $this->db->prepare("INSERT INTO employee_leave_credits (employee_id, leave_type_id, year, credits) VALUES (?, ?, ?, ?)");
        $hist_ins = $this->db->prepare("INSERT INTO leave_credit_history (employee_id, leave_type_id, old_credits, new_credits, change_type, reason, changed_by)
                                         VALUES (?, ?, 0, ?, 'set', '$reason', $cb_sql)");

        $seeded = 0;
        foreach ($eligible_ids as $eid) {
            foreach ($types as $tid => $allowance) {
                if (!empty($have[$eid][$tid])) continue;   // already set — leave it alone
                $cred_ins->bind_param('iiid', $eid, $tid, $year, $allowance);
                $cred_ins->execute();
                $hist_ins->bind_param('iid', $eid, $tid, $allowance);
                $hist_ins->execute();
                $seeded++;
            }
        }

        return ['result' => true, 'seeded' => $seeded,
                'message' => $seeded > 0
                    ? "Initialized $seeded leave credit balance(s) to their type's default for $year."
                    : 'Everyone already has credits set for this year — nothing to initialize.'];
    }

    /* ──────────────────────────────────────────────────────────────
     * Calendar / Holidays
     * ────────────────────────────────────────────────────────────── */

    function save_pay_settings()
    {
        $uid  = $_SESSION['login_id'] ?? null;
        $keys = ['legal_holiday_rate', 'special_holiday_rate', 'ot_regular_rate', 'ot_holiday_multiplier', 'nsd_rate', 'rest_day_rate', 'sanity_net_swing_pct'];
        // Attendance pairing, not a pay rate: how early a punch may be and still
        // count as the day's time-in. dtr_early_grace_hours() clamps it to 0–24
        // on read, so a hand-edited row can't break ingestion either.
        $keys[] = 'dtr_early_grace_hours';
        // Late (tardiness) rules — dtr_late_rules() in db_connect.php. late_mode
        // is a radio (0 exact / 1 brackets); the rest are minutes / hours.
        // Brackets must ascend and the half-day cutoff must not sit inside a
        // bracket, or the rule table would contradict itself.
        $keys = array_merge($keys, ['late_mode', 'late_grace_minutes',
            'late_bracket_1_max', 'late_bracket_1_hours',
            'late_bracket_2_max', 'late_bracket_2_hours', 'late_half_day_after']);
        if (isset($_POST['late_bracket_1_max'], $_POST['late_bracket_2_max'], $_POST['late_half_day_after'])) {
            $g  = (float) ($_POST['late_grace_minutes'] ?? 0);
            $b1 = (float) $_POST['late_bracket_1_max'];
            $b2 = (float) $_POST['late_bracket_2_max'];
            $hd = (float) $_POST['late_half_day_after'];
            if (!($g < $b1 && $b1 < $b2 && $b2 <= $hd)) {
                return ['result' => false, 'message' => 'Late rules must ascend: grace < bracket 1 < bracket 2 ≤ half-day cutoff (all in minutes after the shift start).'];
            }
        }
        // Withholding method is a radio (1 = per-cutoff, 2 = cumulative), so it
        // always posts a value and needs no unchecked-means-zero handling.
        $keys[] = 'tax_method';
        // Checkboxes: absent from POST = unchecked = 0.
        $flag_keys = ['th13_include_paid_leave', 'th13_include_allowance', 'th13_round_to_peso', 'tax_auto_post', 'rest_day_auto_authorize'];
        foreach ($flag_keys as $fk) {
            $_POST[$fk] = isset($_POST[$fk]) && $_POST[$fk] ? 1 : 0;
        }
        $keys = array_merge($keys, $flag_keys);
        $this->db->begin_transaction();
        try {
            foreach ($keys as $key) {
                if (!isset($_POST[$key])) continue;
                $val  = floatval($_POST[$key]);
                $stmt = $this->db->prepare(
                    "INSERT INTO pay_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE setting_value=?, updated_by=?"
                );
                $stmt->bind_param('sdidi', $key, $val, $uid, $val, $uid);
                $stmt->execute();
            }

            // Payroll period type — drives biometric DTR batching. setting_value is a float column,
            // so store a numeric code: 1=semi_monthly (default), 2=weekly, 3=monthly.
            if (isset($_POST['payroll_period'])) {
                $map  = ['semi_monthly' => 1, 'weekly' => 2, 'monthly' => 3];
                $code = isset($map[$_POST['payroll_period']]) ? $map[$_POST['payroll_period']] : 1;
                $pk   = 'payroll_period';
                $stmt = $this->db->prepare(
                    "INSERT INTO pay_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE setting_value=?, updated_by=?"
                );
                $stmt->bind_param('sdidi', $pk, $code, $uid, $code, $uid);
                $stmt->execute();
            }

            $this->db->commit();
            return ['result' => true, 'message' => 'Pay settings saved.'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    // ══════════════════ 13th Month Pay (PD 851) ══════════════════

    /** Numeric flag/value from pay_settings, with a default when unset. */
    /** One numeric row out of pay_settings, or $default when unset. */
    private function pay_setting($key, $default)
    {
        $key = $this->db->real_escape_string($key);
        $r = $this->db->query("SELECT setting_value FROM pay_settings WHERE setting_key = '$key'");
        $row = $r ? $r->fetch_assoc() : null;
        return $row !== null ? (float) $row['setting_value'] : $default;
    }

    /** Kept as the 13th-month-flavoured name its callers already use. */
    private function th13_setting($key, $default)
    {
        return $this->pay_setting($key, $default);
    }

    /**
     * Scan every payroll of the given year and (re)build the draft rows:
     * per employee, BASIC salary actually paid per cutoff, summed, ÷ 12.
     *   daily        → (present [+ paid_leave]) × per_day  [+ allowance]
     *   monthly/fixed→ basic_pay / 2 − absent × per_day [+ allowance]
     * All payrolls with items count (any status); unlocked ones are tallied
     * separately so the UI can warn the figures may still move.
     * Refuses to run once the year is finalized.
     */
    function th13_generate()
    {
        $year = (int) ($_POST['year'] ?? 0);
        if ($year < 2000 || $year > 2100) return ['result' => false, 'message' => 'Invalid year.'];

        $fin = $this->db->query("SELECT 1 FROM thirteenth_month WHERE year = $year AND status = 1 LIMIT 1");
        if ($fin && $fin->num_rows > 0) {
            return ['result' => false, 'message' => "$year is finalized. Unfinalize it first to regenerate."];
        }

        $inc_leave = $this->th13_setting('th13_include_paid_leave', 1) >= 1;
        $inc_allow = $this->th13_setting('th13_include_allowance', 0) >= 1;
        $round_p   = $this->th13_setting('th13_round_to_peso', 0) >= 1;

        $excluded_clasif = "'" . implode("','", array_map([$this->db, 'real_escape_string'], PAYROLL_EXCLUDED_CLASSIFICATIONS)) . "'";

        $leave_term = $inc_leave ? " + COALESCE(pi.paid_leave, 0)" : "";
        $allow_term = $inc_allow ? " + (pi.allowance_amount * pi.allowance_days)" : "";

        // Per-cutoff basic actually paid, following each item's frozen rate_type.
        $q = $this->db->query("
            SELECT pi.employee_id,
                   COUNT(*) AS cutoffs,
                   SUM(p.status <> 2) AS unlocked_cutoffs,
                   SUM(CASE WHEN pi.rate_type IN ('monthly','fixed')
                        THEN pi.basic_pay / 2 - (pi.absent * pi.per_day) $allow_term
                        ELSE ((pi.present $leave_term) * pi.per_day) $allow_term
                   END) AS basic_earned
            FROM payroll_items pi
            INNER JOIN payroll p ON p.id = pi.payroll_id
            INNER JOIN employee e ON e.id = pi.employee_id
            WHERE YEAR(p.date_from) = $year
              AND e.clasification_id NOT IN (SELECT id FROM clasification WHERE UPPER(clasification) IN ($excluded_clasif))
            GROUP BY pi.employee_id
        ");
        if (!$q) return ['result' => false, 'message' => $this->db->error];

        $uid = (int) ($_SESSION['login_id'] ?? 0);
        $this->db->begin_transaction();
        try {
            $seen = [];
            $ins = $this->db->prepare("
                INSERT INTO thirteenth_month (year, employee_id, basic_earned, cutoffs, unlocked_cutoffs, amount, generated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE basic_earned = VALUES(basic_earned), cutoffs = VALUES(cutoffs),
                    unlocked_cutoffs = VALUES(unlocked_cutoffs), amount = VALUES(amount), generated_by = VALUES(generated_by)
            ");
            $n = 0;
            while ($row = $q->fetch_assoc()) {
                $basic = max(0, (float) $row['basic_earned']);
                $amount = $basic / 12;
                if ($round_p) $amount = round($amount);
                $eid = (int) $row['employee_id'];
                $cut = (int) $row['cutoffs'];
                $unl = (int) $row['unlocked_cutoffs'];
                $ins->bind_param('iidiidi', $year, $eid, $basic, $cut, $unl, $amount, $uid);
                $ins->execute();
                $seen[] = $eid;
                $n++;
            }
            // Drop draft rows for employees no longer in the year's payrolls.
            if ($seen) {
                $in = implode(',', $seen);
                $this->db->query("DELETE FROM thirteenth_month WHERE year = $year AND status = 0 AND employee_id NOT IN ($in)");
            } else {
                $this->db->query("DELETE FROM thirteenth_month WHERE year = $year AND status = 0");
            }
            $this->db->commit();
            return ['result' => true, 'message' => "Computed 13th month pay for $n employee(s)."];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Post a finalized year's 13th month pay into a payroll run.
     *
     * The module computed and printed 13th month but nothing ever PAID it: no
     * payroll_items row, no payslip line, no bank payout entry — and so no tax
     * was ever withheld on the portion above the ₱90,000 exemption, even though
     * the BIR alphalist dutifully reported that excess as taxable.
     *
     * Each employee's amount is posted as a payroll_item_extras row (kind 2 =
     * adds to gross), the same mechanism named one-off allowances already use,
     * so it flows into gross, net, the payslip and the bank file with no new
     * plumbing. compute_payroll_tax() picks up the taxable excess from there.
     *
     * Idempotent: re-posting replaces this run's 13th-month extras rather than
     * stacking a second copy on top.
     */
    function th13_post_to_payroll()
    {
        $year      = (int) ($_POST['year'] ?? 0);
        $payrollId = (int) ($_POST['payroll_id'] ?? 0);
        if ($year < 2000 || $payrollId <= 0) return ['result' => false, 'message' => 'Invalid year or payroll.'];

        $pay = $this->db->query("SELECT id, status FROM payroll WHERE id = $payrollId")->fetch_assoc();
        if (!$pay) return ['result' => false, 'message' => 'Payroll not found.'];
        if ((int) $pay['status'] === 2) {
            return ['result' => false, 'message' => 'That payroll is locked. Unlock it first.'];
        }
        if (!($h = $this->db->query("SHOW TABLES LIKE 'payroll_item_extras'")) || !$h->num_rows) {
            return ['result' => false, 'message' => 'payroll_item_extras is missing — run the migrations first.'];
        }

        // Finalized rows only: a draft can still be regenerated, and paying out
        // a figure that may still move is exactly the mistake worth preventing.
        $rows = $this->db->query(
            "SELECT employee_id, amount, override_amount, status
               FROM thirteenth_month WHERE year = $year AND status = 1"
        );
        if (!$rows || !$rows->num_rows) {
            return ['result' => false, 'message' => "No finalized 13th month rows for $year. Finalize the year first."];
        }

        $this->db->begin_transaction();
        try {
            $this->db->query(
                "DELETE FROM payroll_item_extras
                  WHERE payroll_id = $payrollId AND label = '13th Month Pay'"
            );

            $uid = (int) ($_SESSION['login_id'] ?? 0);
            $ins = $this->db->prepare(
                "INSERT INTO payroll_item_extras
                    (payroll_id, payroll_item_id, employee_id, kind, label, amount, created_by)
                 VALUES (?, ?, ?, 2, '13th Month Pay', ?, ?)"
            );
            if (!$ins) throw new Exception($this->db->error);

            $posted = 0; $total = 0.0; $skipped = 0;
            while ($t = $rows->fetch_assoc()) {
                $eid = (int) $t['employee_id'];
                $amt = $t['override_amount'] !== null ? (float) $t['override_amount'] : (float) $t['amount'];
                if ($amt <= 0) continue;

                $it = $this->db->query(
                    "SELECT id FROM payroll_items
                      WHERE payroll_id = $payrollId AND employee_id = $eid LIMIT 1"
                )->fetch_assoc();
                // Not in this run (resigned, or a different site) — nothing to
                // attach to. Counted and reported rather than silently dropped.
                if (!$it) { $skipped++; continue; }

                $iid = (int) $it['id'];
                $ins->bind_param('iiidi', $payrollId, $iid, $eid, $amt, $uid);
                $ins->execute();
                $posted++; $total += $amt;
            }

            // Tax first (the ₱90k excess is taxable compensation), then nets.
            $this->compute_payroll_tax($payrollId);
            $this->resync_payroll_nets($payrollId);
            $this->db->commit();

            $msg = "Posted 13th month pay for $posted employee(s), total "
                 . number_format($total, 2) . '.';
            if ($skipped) $msg .= " $skipped had no row in this payroll and were skipped.";
            return ['result' => true, 'message' => $msg, 'posted' => $posted, 'skipped' => $skipped, 'total' => round($total, 2)];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
    }

    /** Save a manual final amount + remarks on one draft row. */
    function th13_save_row()
    {
        $id = (int) ($_POST['id'] ?? 0);
        $override = $_POST['override_amount'] ?? '';
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (!$id) return ['result' => false, 'message' => 'Invalid row.'];

        $chk = $this->db->query("SELECT status FROM thirteenth_month WHERE id = $id")->fetch_assoc();
        if (!$chk) return ['result' => false, 'message' => 'Row not found.'];
        if ((int) $chk['status'] === 1) return ['result' => false, 'message' => 'Finalized — unfinalize the year first.'];

        $stmt = ($override === '' || $override === null)
            ? $this->db->prepare("UPDATE thirteenth_month SET override_amount = NULL, remarks = ? WHERE id = ?")
            : null;
        if ($stmt) {
            $stmt->bind_param('si', $remarks, $id);
        } else {
            $ov = (float) $override;
            $stmt = $this->db->prepare("UPDATE thirteenth_month SET override_amount = ?, remarks = ? WHERE id = ?");
            $stmt->bind_param('dsi', $ov, $remarks, $id);
        }
        $stmt->execute();
        return ['result' => true, 'message' => 'Saved.'];
    }

    /** Lock (or unlock, admin only) every row of a year. */
    function th13_set_final()
    {
        $year = (int) ($_POST['year'] ?? 0);
        $to = (int) ($_POST['finalize'] ?? 1) === 1 ? 1 : 0;
        if (!$year) return ['result' => false, 'message' => 'Invalid year.'];
        if ($to === 0 && (int) ($_SESSION['login_role'] ?? 0) !== 1) {
            return ['result' => false, 'message' => 'Only an administrator can unfinalize.'];
        }
        $this->db->query("UPDATE thirteenth_month SET status = $to WHERE year = $year");
        return ['result' => true, 'message' => $to ? 'Finalized — rows are now locked.' : 'Unfinalized — rows are editable again.'];
    }

    function edit_dtr_time()
    {
        $id       = intval($_POST['id'] ?? 0);
        $date     = trim($_POST['date'] ?? '');
        $time_in  = trim($_POST['time_in'] ?? '');
        $time_out = trim($_POST['time_out'] ?? '');
        if (!$id || !$date || !$time_in) return ['result' => false, 'message' => 'Missing fields'];

        $in_ts  = strtotime($date . ' ' . $time_in);
        $out_ts = $time_out ? strtotime($date . ' ' . $time_out) : null;
        if ($out_ts && $out_ts <= $in_ts) $out_ts = strtotime('+1 day', $out_ts);

        $logs = [['dateTime' => date('Y-m-d H:i:s', $in_ts), 'type' => 'manual']];
        if ($out_ts) $logs[] = ['dateTime' => date('Y-m-d H:i:s', $out_ts), 'type' => 'manual'];

        // Figures via the shared day math (dtr_compute_day, db_connect.php) —
        // identical to the incident repair and batch recompute.
        $emp_row = $this->db->query("SELECT employee_id FROM DTR_details WHERE id = $id")->fetch_assoc();
        if (!$emp_row) return ['result' => false, 'message' => 'Record not found'];
        $c = dtr_compute_day($this->db, (int)$emp_row['employee_id'], $date, $out_ts ? [$in_ts, $out_ts] : [$in_ts]);
        $work_hours  = $c['work_hours'];
        $overtime    = $c['overtime'];
        $undertime   = $c['undertime'];
        $late        = $c['late'];
        $nsd_hours   = $c['nsd_hours'];
        $day_type    = $c['day_type'];
        $is_complete = $c['is_complete'];

        $json_logs = json_encode($logs);
        // schedule_id/day_hours/is_rest_day come from the same stamp-mode call:
        // a stamped row keeps its frozen shift, a legacy unstamped one gets
        // stamped now so it stops drifting with later roster edits.
        $stmt = $this->db->prepare(
            "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, late=?, undertime=?,
             day_type=?, nsd_hours=?, is_complete=?, attendance_type='manual',
             schedule_id=?, day_hours=?, is_rest_day=?,
             sched_start=?, sched_end=?, sched_break=?, sched_graveyard=? WHERE id=?"
        );
        $stmt->bind_param(
            'sddddsdiidissiii',
            $json_logs,
            $work_hours,
            $overtime,
            $late,
            $undertime,
            $day_type,
            $nsd_hours,
            $is_complete,
            $c['schedule_id'],
            $c['day_hours'],
            $c['is_rest_day'],
            $c['sched_start'],
            $c['sched_end'],
            $c['sched_break'],
            $c['sched_graveyard'],
            $id
        );
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Saved'];
    }

    /**
     * Replace one DTR day's punch list outright — any number of punches, each
     * with its own full date and time.
     *
     * edit_dtr_time() next door can only express ONE in and ONE out, both on the
     * row's own date. That covers a plain day shift and nothing else: a split
     * shift, a forgotten mid-day scan, a double-tap to remove, and above all a
     * night shift whose clock-out lands the NEXT morning all need either more
     * than two punches or a punch on another date. Admins were left correcting
     * the computed hours by hand instead of the punches that produce them, which
     * a later Recompute then silently overwrote.
     *
     * Each punch keeps its own provenance: a scan the admin did not touch stays
     * 'bio', anything added or edited becomes 'manual' and carries who
     * authorised it. Relabelling an untouched device scan as manual would
     * destroy the one thing that makes the raw log worth keeping.
     *
     * Figures come from dtr_compute_day in STAMP mode — the same math as the
     * manual time edit and the incident repair — so this corrects the punches,
     * not the shift. Changing the shift is dtr_set_day_schedule's job.
     */
    function save_dtr_punches()
    {
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];

        $id = (int) ($_POST['detail_id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing record id'];

        $row = $this->db->query(
            "SELECT d.employee_id, d.date_time, d.logs, b.status AS batch_status
             FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
             WHERE d.id = $id LIMIT 1"
        );
        $row = $row ? $row->fetch_assoc() : null;
        if (!$row) return ['result' => false, 'message' => 'Record not found.'];
        if ((int) $row['batch_status'] === 2) {
            return ['result' => false, 'message' => 'This DTR batch is already final-approved and locked — reopen it before editing punches.'];
        }

        $employee_id = (int) $row['employee_id'];
        $date        = date('Y-m-d', strtotime($row['date_time']));
        $author      = mb_substr(trim((string) ($_SESSION['login_name'] ?? $_SESSION['name'] ?? 'Admin')), 0, 80);

        // Which stamps were already on the row, so an untouched one keeps 'bio'.
        $wasBio = [];
        foreach ((json_decode((string) $row['logs'], true) ?: []) as $lg) {
            $t = strtotime($lg['dateTime'] ?? '');
            if ($t !== false && ($lg['type'] ?? '') === 'bio') $wasBio[date('Y-m-d H:i', $t)] = true;
        }

        $in = $_POST['punches'] ?? [];
        if (!is_array($in)) $in = [];
        if (count($in) > 12) return ['result' => false, 'message' => 'A day can hold at most 12 punches.'];

        $ts = [];
        foreach ($in as $p) {
            $raw = trim((string) (is_array($p) ? ($p['dt'] ?? '') : $p));
            if ($raw === '') continue;
            $t = strtotime(str_replace('T', ' ', $raw));
            if ($t === false) return ['result' => false, 'message' => 'Could not read the time "' . htmlspecialchars($raw) . '".'];
            // A punch belongs to the shift that started on this row's date. Two
            // days either side is generous for even a 12-hour shift plus OT, and
            // it stops a typo'd year from silently landing on a random day.
            $off = (int) round(($t - strtotime($date)) / 86400);
            if ($off < -1 || $off > 2) {
                return ['result' => false, 'message' => 'Punch ' . date('M j, g:i A', $t) . ' is too far from ' . date('M j', strtotime($date)) . ' to belong to that day.'];
            }
            $ts[] = $t;
        }
        sort($ts);
        $ts = array_values(array_unique($ts));

        // A device scan is evidence: the editor may not drop or retime one, only
        // manual entries. Enforced here and not just by the read-only field, so
        // a crafted POST cannot erase what the machine recorded.
        //
        // Re-filing a scan onto ANOTHER day is deliberately still allowed — that
        // happens by adding it from the other day's editor, which moves it (see
        // the adoption pass below). The scan survives; only its date changes.
        $have = [];
        foreach ($ts as $t) $have[date('Y-m-d H:i', $t)] = true;
        $lost = array_diff(array_keys($wasBio), array_keys($have));
        if ($lost) {
            return ['result' => false, 'message' => 'The device scan at '
                . date('M j, g:i A', strtotime((string) reset($lost)))
                . ' cannot be removed or retimed — only manual entries can. If it belongs on another day, add it from that day\'s punch editor and it will move across.'];
        }

        // A punch typed onto this row that already exists as a scan on a
        // NEIGHBOURING day is the same punch being re-filed, not a new one —
        // exactly what an admin does to put a night shift's 7:03 AM clock-out
        // back onto the day the shift started. So MOVE it: keep whatever
        // provenance it had, and take it off the day it came from. Copying
        // would leave one scan counted on two days at once, and retyping it as
        // 'manual' would erase the fact that a device recorded it.
        $adopt = $strip = [];
        $want  = [];
        foreach ($ts as $t) {
            $k = date('Y-m-d H:i', $t);
            if (!isset($wasBio[$k])) $want[$k] = $t;
        }
        if ($want) {
            $nq = $this->db->query(
                "SELECT d.id, d.date_time, d.logs FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
                 WHERE d.employee_id = $employee_id AND d.id <> $id AND b.status <> 2
                   AND d.date_time BETWEEN '" . $this->db->real_escape_string(date('Y-m-d', strtotime('-2 day', strtotime($date)))) . "'
                                       AND '" . $this->db->real_escape_string(date('Y-m-d', strtotime('+2 day', strtotime($date)))) . "'"
            );
            while ($nq && ($nr = $nq->fetch_assoc())) {
                $keep = [];
                $hit  = false;
                foreach ((json_decode((string) $nr['logs'], true) ?: []) as $lg) {
                    $t = strtotime($lg['dateTime'] ?? '');
                    $k = $t !== false ? date('Y-m-d H:i', $t) : null;
                    if ($k !== null && isset($want[$k]) && !isset($adopt[$k])) {
                        $adopt[$k] = $lg;   // provenance travels with the punch
                        $hit = true;
                        continue;           // and it leaves this row
                    }
                    $keep[] = $lg;
                }
                if ($hit) $strip[(int) $nr['id']] = ['date' => date('Y-m-d', strtotime($nr['date_time'])), 'logs' => $keep];
            }
        }

        $logs = [];
        $manual = false;
        foreach ($ts as $t) {
            $key = date('Y-m-d H:i', $t);
            if (isset($wasBio[$key])) {
                $logs[] = ['dateTime' => date('Y-m-d H:i:s', $t), 'type' => 'bio'];
            } elseif (isset($adopt[$key])) {
                $lg = $adopt[$key];
                $lg['dateTime'] = date('Y-m-d H:i:s', $t);
                if (($lg['type'] ?? '') !== 'bio') $manual = true;
                $logs[] = $lg;
            } else {
                $manual = true;
                $logs[] = ['dateTime' => date('Y-m-d H:i:s', $t), 'type' => 'manual', 'authorized_by' => $author];
            }
        }

        $c    = dtr_compute_day($this->db, $employee_id, $date, $ts);
        $json = json_encode($logs);
        $atype = $manual ? 'manual' : 'biometric';

        $this->db->begin_transaction();
        try {
            // Edited punches void any earlier decision — back to pending, exactly
            // as the figure edits next door do.
            $stmt = $this->db->prepare(
                "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, undertime=?, late=?, nsd_hours=?,
                 day_type=?, is_complete=?, attendance_type=?,
                 schedule_id=?, day_hours=?, is_rest_day=?, sched_start=?, sched_end=?, sched_break=?, sched_graveyard=?,
                 status=0, decision_note=NULL, decided_by=NULL, decided_at=NULL WHERE id=?"
            );
            $stmt->bind_param(
                'sdddddsisidissiii',
                $json, $c['work_hours'], $c['overtime'], $c['undertime'], $c['late'], $c['nsd_hours'],
                $c['day_type'], $c['is_complete'], $atype,
                $c['schedule_id'], $c['day_hours'], $c['is_rest_day'],
                $c['sched_start'], $c['sched_end'], $c['sched_break'], $c['sched_graveyard'], $id
            );
            if (!$stmt->execute()) throw new Exception($stmt->error);

            // Each day a punch was taken from has to be re-derived too, or it
            // keeps the hours it earned from a scan it no longer holds.
            foreach ($strip as $nid => $n) {
                $nts = [];
                foreach ($n['logs'] as $lg) {
                    $t = strtotime($lg['dateTime'] ?? '');
                    if ($t !== false) $nts[] = $t;
                }
                sort($nts);
                $nc = dtr_compute_day($this->db, $employee_id, $n['date'], $nts);
                $nj = json_encode($n['logs']);
                $ns = $this->db->prepare(
                    "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, undertime=?, late=?, nsd_hours=?,
                     day_type=?, is_complete=?, status=0, decision_note=NULL, decided_by=NULL, decided_at=NULL WHERE id=?"
                );
                $ns->bind_param('sdddddsii', $nj, $nc['work_hours'], $nc['overtime'], $nc['undertime'],
                                $nc['late'], $nc['nsd_hours'], $nc['day_type'], $nc['is_complete'], $nid);
                if (!$ns->execute()) throw new Exception($ns->error);
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Save failed — nothing was changed. ' . $e->getMessage()];
        }

        $movedFrom = array_values(array_unique(array_map(function ($n) {
            return date('M j', strtotime($n['date']));
        }, $strip)));

        return [
            'moved_from' => $movedFrom,
            'result'      => true,
            'work_hours'  => (float) $c['work_hours'],
            'overtime'    => (float) $c['overtime'],
            'undertime'   => (float) $c['undertime'],
            'late'        => (float) $c['late'],
            'is_complete' => (int) $c['is_complete'],
            'logs'        => array_map(function ($l) {
                $t = strtotime($l['dateTime']);
                return ['t' => date('g:i A', $t), 'dt' => date('Y-m-d\TH:i', $t), 'bio' => $l['type'] === 'bio'];
            }, $logs),
            'message'     => (count($logs)
                    ? count($logs) . ' punch(es) saved — the day was recalculated and sent back to Pending.'
                    : 'All punches removed — the day is now blank and back to Pending.')
                . ($movedFrom ? ' Moved off ' . implode(' and ', $movedFrom) . ', which was recalculated too.' : ''),
        ];
    }

    function finalize_dtr()
    {
        $id   = intval($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("UPDATE DTR_details SET is_complete=1 WHERE id=?");
        $stmt->bind_param('i', $id);
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Finalized'];
    }

    // Bulk-finalize DTR_details records. Accepts either an explicit list of record ids
    // (checkbox selection), or a whole DTR batch (ddtr_id) optionally limited to one day.
    function finalize_dtr_bulk()
    {
        // Explicit id list (checkbox selection in DTR Details) takes precedence.
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "UPDATE DTR_details SET is_complete=1 WHERE is_complete=0 AND id IN ($placeholders)"
            );
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            if (!$stmt->execute()) {
                return ['result' => false, 'message' => $stmt->error];
            }
            return ['result' => true, 'message' => 'Records marked complete', 'affected' => $stmt->affected_rows];
        }

        $ddtr_id = intval($_POST['ddtr_id'] ?? 0);
        $date    = trim($_POST['date'] ?? '');
        if (!$ddtr_id) return ['result' => false, 'message' => 'Missing ddtr_id'];

        if ($date !== '') {
            $stmt = $this->db->prepare(
                "UPDATE DTR_details SET is_complete=1 WHERE ddtr_id=? AND DATE(date_time)=? AND is_complete=0"
            );
            $stmt->bind_param('is', $ddtr_id, $date);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE DTR_details SET is_complete=1 WHERE ddtr_id=? AND is_complete=0"
            );
            $stmt->bind_param('i', $ddtr_id);
        }
        if (!$stmt->execute()) {
            return ['result' => false, 'message' => $stmt->error];
        }
        return ['result' => true, 'message' => 'Records marked complete', 'affected' => $stmt->affected_rows];
    }

    // Approve / disapprove attendance records (DTR_details.status): 1=approved, 2=disapproved.
    // Accepts an explicit id list (checkbox selection), or a whole batch (ddtr_id) optionally by day.
    function decide_dtr_details()
    {
        $decision = (int)($_POST['decision'] ?? 0); // 1 = approve, 2 = disapprove
        if (!in_array($decision, [1, 2], true)) {
            return ['result' => false, 'message' => 'Invalid decision'];
        }

        // Disapprovals carry a reason so the employee's review round isn't
        // arguing against a blank rejection; approvals clear any stale note.
        $note = $decision === 2 ? substr(trim($_POST['note'] ?? ''), 0, 255) : '';
        if ($decision === 2 && $note === '') {
            return ['result' => false, 'message' => 'A reason is required to disapprove.'];
        }
        $noteSql = $decision === 2 ? $note : null;

        // Audit stamp: every decision records who made it and when.
        $decider = (int)($_SESSION['login_id'] ?? 0) ?: null;
        $setSql  = "status = ?, decision_note = ?, decided_by = ?, decided_at = NOW()";

        // ANY work recorded on a rest day needs a filed & approved rest-day (or
        // legacy overtime) request before the record can be approved — matches
        // ot_request_limit()'s own rule (db_connect.php), which now offers the
        // whole rest day for filing rather than only the part beyond the duty.
        //
        // This used to gate on `overtime > 0` alone, back when base rest-day
        // hours were auto-authorized and the filing form refused to accept
        // them. Under that split a day off worked exactly to the shift (OT = 0)
        // was approved and paid with nobody ever authorizing the duty.
        // pay_settings.rest_day_auto_authorize off (the default) enforces
        // this outright rather than just warning. Disapprovals are never
        // blocked — rejecting a bad record must always stay possible. The
        // EXISTS subquery is correlated to DTR_details by table name, not an
        // alias — legal in a single-table UPDATE as long as the subquery
        // itself selects from a different table.
        $restAuto = $this->pay_setting('rest_day_auto_authorize', 0) >= 1;
        $restBlockSql = ($decision === 1 && !$restAuto)
            ? " AND NOT (is_rest_day = 1 AND (work_hours > 0 OR overtime > 0) AND NOT EXISTS (
                    SELECT 1 FROM attendance_requests ar
                    WHERE ar.employee_id = DTR_details.employee_id
                      AND ar.request_type IN ('rest_day', 'overtime') AND ar.status = 1
                      AND ar.request_date = DATE(DTR_details.date_time)
                ))"
            : '';

        // Explicit id list (checkbox selection) takes precedence.
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) {
            $blockedIds = [];
            if ($restBlockSql !== '') {
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                $chk = $this->db->prepare(
                    "SELECT id FROM DTR_details
                     WHERE id IN ($ph) AND is_rest_day = 1 AND (work_hours > 0 OR overtime > 0)
                       AND NOT EXISTS (
                           SELECT 1 FROM attendance_requests ar
                           WHERE ar.employee_id = DTR_details.employee_id
                             AND ar.request_type IN ('rest_day', 'overtime') AND ar.status = 1
                             AND ar.request_date = DATE(DTR_details.date_time)
                       )"
                );
                $chk->bind_param(str_repeat('i', count($ids)), ...$ids);
                $chk->execute();
                $res = $chk->get_result();
                while ($row = $res->fetch_assoc()) $blockedIds[] = (int) $row['id'];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt   = $this->db->prepare("UPDATE DTR_details SET $setSql WHERE id IN ($placeholders)" . $restBlockSql);
            $types  = 'isi' . str_repeat('i', count($ids));
            $params = array_merge([$decision, $noteSql, $decider], $ids);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];
            $msg = 'Records updated';
            if ($blockedIds) {
                $msg = $stmt->affected_rows . ' updated; ' . count($blockedIds)
                     . ' skipped — rest-day work with no approved Rest Day Work request on file.';
            }
            return [
                'result'      => true,
                'message'     => $msg,
                'affected'    => $stmt->affected_rows,
                'blocked_ids' => $blockedIds,
            ];
        }

        // Whole batch (ddtr_id), optionally a single day — only touches still-pending rows.
        // clean_only limits a batch-wide approval to records with no exception
        // flags (rule lives in dtr_clean_condition_sql: time-out present,
        // non-zero hours, OT within DTR_HIGH_OT_HOURS, employee attendance at
        // least DTR_LOW_ATTENDANCE_PCT of the period); the rest stay pending.
        $ddtr_id    = (int)($_POST['ddtr_id'] ?? 0);
        $date       = trim($_POST['date'] ?? '');
        $clean_only = !empty($_POST['clean_only']);
        if (!$ddtr_id) return ['result' => false, 'message' => 'Missing ids or ddtr_id'];

        $cleanSql = '';
        if ($clean_only) {
            $hdr = $this->db->query("SELECT date_from, date_to FROM DTR WHERE id = $ddtr_id")->fetch_assoc();
            $periodDays = 0;
            if ($hdr && $hdr['date_from'] && $hdr['date_to']) {
                $df = date_create($hdr['date_from']);
                $dt = date_create($hdr['date_to']);
                if ($df && $dt) $periodDays = (int)$df->diff($dt)->days + 1;
            }
            $cleanSql = dtr_clean_condition_sql($ddtr_id, dtr_min_days($periodDays));
        }
        if ($date !== '') {
            $stmt = $this->db->prepare("UPDATE DTR_details SET $setSql WHERE ddtr_id = ? AND DATE(date_time) = ? AND status = 0" . $cleanSql . $restBlockSql);
            $stmt->bind_param('isiis', $decision, $noteSql, $decider, $ddtr_id, $date);
        } else {
            $stmt = $this->db->prepare("UPDATE DTR_details SET $setSql WHERE ddtr_id = ? AND status = 0" . $cleanSql . $restBlockSql);
            $stmt->bind_param('isii', $decision, $noteSql, $decider, $ddtr_id);
        }
        if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];
        return ['result' => true, 'message' => 'Records updated', 'affected' => $stmt->affected_rows];
    }

    function delete_dtr_record()
    {
        $id   = intval($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("DELETE FROM DTR_details WHERE id=?");
        $stmt->bind_param('i', $id);
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Deleted'];
    }

    /**
     * Recompute every record of a batch from its raw logs using the shared
     * day math (dtr_compute_day) with the employee's CURRENT schedule
     * assignment + the holiday calendar. This is the one deliberate override
     * of the shift frozen on each row at punch time: pressing Recompute means
     * "the roster is right, the rows are wrong" — e.g. admin forgot to change
     * an employee's schedule before the period ran. The re-resolved shift is
     * stamped back onto each row (schedule_id / day_hours / is_rest_day) so
     * payroll and the DTR sheets follow it too.
     *
     * Policy: figures are updated in place; APPROVED rows whose figures
     * actually changed are reset to pending for re-approval (the approval
     * was given for the old numbers). Disapproved rows keep their status —
     * rejection reasons are usually independent of the figures. Rows whose
     * figures come out identical are left completely untouched.
     */
    function recompute_dtr()
    {
        $role = (int)($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];

        $ddtr_id = (int)($_POST['id'] ?? 0);
        if (!$ddtr_id) return ['result' => false, 'message' => 'Missing DTR id'];
        $batch = $this->db->query("SELECT id, status, date_from, date_to FROM DTR WHERE id = $ddtr_id")->fetch_assoc();
        if (!$batch) return ['result' => false, 'message' => 'Batch not found'];
        if ((int)$batch['status'] === 2) {
            return ['result' => false, 'message' => 'This batch is final-approved — its figures are locked.'];
        }

        $res = $this->db->query("SELECT id, employee_id, date_time, work_hours, overtime, undertime,
                                        late, nsd_hours, day_type, status, logs,
                                        schedule_id, day_hours, is_rest_day,
                                        sched_start, sched_end, sched_break, sched_graveyard
                                 FROM DTR_details WHERE ddtr_id = $ddtr_id");

        $this->db->begin_transaction();
        try {
            [$scanned, $changed, $repending, $affectedEmp] = $this->recomputeDetailRows($res);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Recompute failed — nothing was changed. ' . $e->getMessage()];
        }

        $this->logRecompute($ddtr_id, null, 'batch', $scanned, $changed, $repending);

        // Bell + FCM push to each employee whose figures moved (after commit) —
        // numbers they may already have seen or signed off on just changed.
        $periodLbl = date('M j', strtotime($batch['date_from'])) . '–' . date('M j, Y', strtotime($batch['date_to']));
        foreach ($affectedEmp as $empId => $n) {
            $this->notifyEmployee(
                $empId,
                'Attendance recalculated',
                $n . ' day(s) of your attendance for ' . $periodLbl . ' were recalculated after a schedule update. Please review them in your portal.',
                'ri-refresh-line',
                'info',
                'employee-portal.php?tab=attendance'
            );
        }

        return [
            'result'    => true,
            'scanned'   => $scanned,
            'changed'   => $changed,
            'repending' => $repending,
            'message'   => "$scanned record(s) scanned, $changed updated, $repending sent back to pending.",
        ];
    }

    /**
     * Quick single-day schedule correction from the DTR review screen — set
     * (or clear to a plain rest day) the duty-roster override for one
     * employee/date and immediately re-derive that one DTR_details row's
     * figures, so fixing "wrong shift used for OT" doesn't require a trip to
     * duty-roster.php plus a batch Recompute. Reuses recomputeDetailRows(),
     * the same engine the batch/scoped Recompute buttons run.
     */
    function dtr_set_day_schedule()
    {
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];

        $detailId = (int) ($_POST['detail_id'] ?? 0);
        if (!$detailId) return ['result' => false, 'message' => 'Missing record id'];

        $row = $this->db->query(
            "SELECT d.employee_id, d.date_time, b.status AS batch_status
             FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
             WHERE d.id = $detailId LIMIT 1"
        );
        $row = $row ? $row->fetch_assoc() : null;
        if (!$row) return ['result' => false, 'message' => 'Record not found.'];
        if ((int) $row['batch_status'] === 2) {
            return ['result' => false, 'message' => 'This DTR batch is already final-approved and locked — reopen it before changing the schedule.'];
        }

        $employee_id = (int) $row['employee_id'];
        $date        = date('Y-m-d', strtotime($row['date_time']));

        if ($deny = $this->dutyRosterLockDeny())          return ['result' => false, 'message' => $deny];
        if ($deny = $this->dutyDenyWrite([$employee_id])) return ['result' => false, 'message' => $deny];

        $sid  = isset($_POST['schedule_id']) && $_POST['schedule_id'] !== '' ? (int) $_POST['schedule_id'] : null;
        $rest = !empty($_POST['is_rest_day']) ? 1 : 0;
        if ($sid === null && !$rest) return ['result' => false, 'message' => 'Pick a shift, or mark the day as rest.'];
        if ($sid !== null) {
            $chk = $this->db->query("SELECT id FROM work_schedules WHERE id = $sid AND status = 1 LIMIT 1");
            if (!$chk || !$chk->fetch_assoc()) return ['result' => false, 'message' => 'Invalid shift.'];
        }

        $uid  = (int) ($_SESSION['login_id'] ?? 0) ?: null;
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);

        $this->db->begin_transaction();
        try {
            // Same upsert duty_roster_save() uses — published immediately (status=1)
            // rather than left as a draft, since this is a direct correction of an
            // already-recorded day, not a future cutoff awaiting Publish.
            $ins = $this->db->prepare(
                "INSERT INTO employee_day_schedule
                    (employee_id, work_date, schedule_id, is_rest_day, note, created_by, changed_by,
                     status, published_at, planned_schedule_id, planned_is_rest_day)
                 VALUES (?,?,?,?,?,?,?,1,NOW(),?,?)
                 ON DUPLICATE KEY UPDATE schedule_id = VALUES(schedule_id), is_rest_day = VALUES(is_rest_day),
                                         note = VALUES(note), changed_by = VALUES(changed_by), status = 1,
                                         published_at = COALESCE(published_at, NOW())"
            );
            $ins->bind_param('isiisiiii', $employee_id, $date, $sid, $rest, $note, $uid, $uid, $sid, $rest);
            if (!$ins->execute()) throw new Exception($ins->error);

            $res = $this->db->query(
                "SELECT d.id, d.employee_id, d.date_time, d.work_hours, d.overtime, d.undertime,
                        d.late, d.nsd_hours, d.day_type, d.status, d.logs,
                        d.schedule_id, d.day_hours, d.is_rest_day,
                        d.sched_start, d.sched_end, d.sched_break, d.sched_graveyard
                 FROM DTR_details d WHERE d.id = $detailId LIMIT 1"
            );
            [, $changed, $repending] = $this->recomputeDetailRows($res);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Save failed — nothing was changed. ' . $e->getMessage()];
        }

        $fresh = $this->db->query(
            "SELECT work_hours, overtime, undertime, late, is_rest_day, status, schedule_id
             FROM DTR_details WHERE id = $detailId LIMIT 1"
        )->fetch_assoc();

        return [
            'result'      => true,
            'changed'     => (bool) $changed,
            'repending'   => (bool) $repending,
            'work_hours'  => (float) $fresh['work_hours'],
            'overtime'    => (float) $fresh['overtime'],
            'undertime'   => (float) $fresh['undertime'],
            'late'        => (float) $fresh['late'],
            'is_rest_day' => (int) $fresh['is_rest_day'],
            'schedule_id' => $fresh['schedule_id'] !== null ? (int) $fresh['schedule_id'] : null,
            'status'      => (int) $fresh['status'],
            'message'     => !$changed
                ? 'Schedule saved — figures were already correct, nothing to recalculate.'
                : ($repending
                    ? 'Schedule updated and recalculated — sent back to Pending for re-approval.'
                    : 'Schedule updated and recalculated.'),
        ];
    }

    /**
     * Scoped recompute: ONE employee, every open (not final-approved) batch.
     * Backs the "Apply now" button shown right after a schedule assignment,
     * so the admin can apply the fix without opening each affected batch.
     * Same math and re-approval policy as the batch Recompute.
     */
    function recompute_employee_dtr()
    {
        $role = (int)($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];

        $employee_id = (int)($_POST['employee_id'] ?? 0);
        if (!$employee_id) return ['result' => false, 'message' => 'Missing employee id'];

        $res = $this->db->query("SELECT d.id, d.employee_id, d.date_time, d.work_hours, d.overtime, d.undertime,
                                        d.late, d.nsd_hours, d.day_type, d.status, d.logs,
                                        d.schedule_id, d.day_hours, d.is_rest_day,
                                        d.sched_start, d.sched_end, d.sched_break, d.sched_graveyard
                                 FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
                                 WHERE d.employee_id = $employee_id AND b.status <> 2");

        $this->db->begin_transaction();
        try {
            [$scanned, $changed, $repending, ] = $this->recomputeDetailRows($res);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Recompute failed — nothing was changed. ' . $e->getMessage()];
        }

        $this->logRecompute(null, $employee_id, 'employee', $scanned, $changed, $repending);

        if ($changed) {
            $this->notifyEmployee(
                $employee_id,
                'Attendance recalculated',
                $changed . ' day(s) of your attendance were recalculated after a schedule update. Please review them in your portal.',
                'ri-refresh-line',
                'info',
                'employee-portal.php?tab=attendance'
            );
        }

        return [
            'result'    => true,
            'scanned'   => $scanned,
            'changed'   => $changed,
            'repending' => $repending,
            'message'   => "$scanned record(s) scanned, $changed updated, $repending sent back to pending.",
        ];
    }

    /**
     * Shared row-processor for both recompute entry points. Re-derives every
     * row in $res from its raw logs with dtr_compute_day($use_stamp=false) —
     * the current roster wins over the frozen stamp — and writes the new
     * figures + stamp back. Approved rows whose figures change reset to
     * pending. Caller owns the transaction.
     * Returns [scanned, changed, repending, affectedEmp(employee_id => days)].
     */
    private function recomputeDetailRows($res): array
    {
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;

        // Punches ingestion filed under the wrong day have to be re-dated before
        // anything is re-derived: dtr_compute_day reads a row's OWN logs, so
        // otherwise both days recompute from punches sitting on the wrong rows.
        // May append (or open) neighbouring rows, so they recompute here too.
        $this->repairPunchDates($rows);

        $scanned = $changed = $repending = 0;
        $affectedEmp = [];

        $upd = $this->db->prepare(
            "UPDATE DTR_details SET work_hours=?, overtime=?, undertime=?, late=?, nsd_hours=?, day_type=?, is_complete=?,
             schedule_id=?, day_hours=?, is_rest_day=?, sched_start=?, sched_end=?, sched_break=?, sched_graveyard=? WHERE id=?"
        );
        $updPend = $this->db->prepare(
            "UPDATE DTR_details SET work_hours=?, overtime=?, undertime=?, late=?, nsd_hours=?, day_type=?, is_complete=?,
             schedule_id=?, day_hours=?, is_rest_day=?, sched_start=?, sched_end=?, sched_break=?, sched_graveyard=?,
             status=0, decision_note=NULL, decided_by=NULL, decided_at=NULL WHERE id=?"
        );
        foreach ($rows as $row) {
            $scanned++;
            $ts = [];
            foreach ((json_decode($row['logs']) ?: []) as $lg) {
                $t = strtotime($lg->dateTime ?? '');
                if ($t !== false) $ts[] = $t;
            }
            $c = dtr_compute_day($this->db, (int)$row['employee_id'], $row['date_time'], $ts, false);

            $same = abs($c['work_hours'] - (float)$row['work_hours']) < 0.005
                 && abs($c['overtime']   - (float)$row['overtime'])   < 0.005
                 && abs($c['undertime']  - (float)$row['undertime'])  < 0.005
                 && abs($c['late']       - (float)$row['late'])       < 0.005
                 && abs($c['nsd_hours']  - (float)$row['nsd_hours'])  < 0.005
                 && $c['day_type'] === $row['day_type']
                 && (int)($c['schedule_id'] ?? 0) === (int)($row['schedule_id'] ?? 0)
                 && abs((float)($c['day_hours'] ?? 0) - (float)($row['day_hours'] ?? 0)) < 0.005
                 && (int)$c['is_rest_day'] === (int)($row['is_rest_day'] ?? 0)
                 // Boundary stamp too — a shift-definition edit that happens to
                 // leave every figure equal must still refresh the frozen times.
                 && (string)($c['sched_start'] ?? '') === (string)($row['sched_start'] ?? '')
                 && (string)($c['sched_end'] ?? '')   === (string)($row['sched_end'] ?? '')
                 && (int)($c['sched_break'] ?? -1)    === (int)($row['sched_break'] ?? -1)
                 && (int)($c['sched_graveyard'] ?? -1) === (int)($row['sched_graveyard'] ?? -1);
            // Ahead of the $same shortcut on purpose: a row whose figures are
            // already right can still be carrying the label of a shift it no
            // longer computes under, left behind by an earlier recompute.
            $this->refreshShiftNote($row, $c);
            if ($same) continue;

            $changed++;
            $affectedEmp[(int)$row['employee_id']] = ($affectedEmp[(int)$row['employee_id']] ?? 0) + 1;
            $rowId    = (int)$row['id'];
            $toPend   = ((int)$row['status'] === 1);   // only approved rows re-open
            $stmt     = $toPend ? $updPend : $upd;
            if ($toPend) $repending++;
            $stmt->bind_param('dddddsiidissiii', $c['work_hours'], $c['overtime'], $c['undertime'],
                              $c['late'], $c['nsd_hours'], $c['day_type'], $c['is_complete'],
                              $c['schedule_id'], $c['day_hours'], $c['is_rest_day'],
                              $c['sched_start'], $c['sched_end'], $c['sched_break'], $c['sched_graveyard'], $rowId);
            if (!$stmt->execute()) throw new Exception('Row ' . $rowId . ': ' . $stmt->error);
        }
        return [$scanned, $changed, $repending, $affectedEmp];
    }

    /**
     * Keep DTR_details.notes in step with the shift the row now computes under.
     *
     * Ingestion writes the shift it recorded the day against into `notes`
     * ("AM / 7-3 (7AM-3PM) · 7:00 AM–3:00 PM") and the Form 48 prints it, in the
     * day tooltip and the Notes summary. Re-resolving the schedule left that
     * label naming the OLD shift, so a day corrected to nights still read as a
     * morning shift on the sheet — the figures said one thing and the note
     * another.
     *
     * Rewritten ONLY when the note is blank or is one of the labels this app
     * generates — every shift's, not just the stamp being replaced, since a
     * previous recompute may already have moved the stamp on and left the note
     * naming a third shift. Anything an admin typed there matches none of them
     * and survives untouched.
     */
    private function refreshShiftNote(array $row, array $c): void
    {
        // Called for every scanned row; the shift list is small and fixed, so
        // read it once per request.
        static $shifts = null, $generated = null;
        if ($shifts === null) {
            $shifts = $generated = [];
            $q = $this->db->query("SELECT id, description, start_time, end_time FROM work_schedules");
            while ($q && ($s = $q->fetch_assoc())) {
                $shifts[(int) $s['id']] = $s;
                $generated[] = self::shiftNoteLabel($s['description'], $s['start_time'], $s['end_time']);
            }
        }

        $label = function ($schedule_id, $start, $end) use ($shifts) {
            $sid = (int) $schedule_id;
            if (!$sid || !$start || !$end || !isset($shifts[$sid])) return null;
            return self::shiftNoteLabel($shifts[$sid]['description'], $start, $end);
        };

        $now = $label($c['schedule_id'], $c['sched_start'], $c['sched_end']);
        if ($now === null) return;

        // The stamp's own label joins the set: a work_schedules row edited since
        // the punch generates a label that is no longer in the list above, and
        // dropping it would leave the stale note in place forever.
        $was  = $label($row['schedule_id'] ?? null, $row['sched_start'] ?? null, $row['sched_end'] ?? null);
        $pool = array_unique(array_filter(array_merge($generated, [$was])));
        $in   = implode(',', array_map(function ($l) { return "'" . $this->db->real_escape_string($l) . "'"; }, $pool));

        $stmt = $this->db->prepare(
            "UPDATE DTR_details SET notes = ? WHERE id = ? AND (notes IS NULL OR notes = '' OR notes IN ($in))"
        );
        $id = (int) $row['id'];
        $stmt->bind_param('si', $now, $id);
        $stmt->execute();
    }

    /**
     * The shift label written into DTR_details.notes. MUST stay byte for byte
     * identical to save_biometric_attendance's $shift_label, or refreshShiftNote
     * stops recognising the app's own generated notes and leaves them stale.
     */
    private static function shiftNoteLabel($description, $start, $end): string
    {
        return substr($description . ' · '
            . date('g:i A', strtotime($start)) . '–' . date('g:i A', strtotime($end)), 0, 100);
    }

    /**
     * Re-date punches that ingestion filed under the wrong day.
     *
     * Ingestion decides which day a punch belongs to from the roster AS IT STOOD
     * at scan time (Action::save_biometric_attendance). Change the shift AFTER
     * the fact — the ordinary "wrong shift, fix it and Recompute" flow — and the
     * punches stay where the old roster put them. Recompute alone cannot help:
     * it re-derives each row from its own logs and never moves a punch.
     *
     * The rule this pass enforces, in both directions: **the logs must end up
     * where ingestion would have put them had the roster been right at the
     * time.** Same overnight predicate, same 12-hour post-shift OT ceiling, so
     * a repaired day is indistinguishable from one recorded correctly.
     *
     * PULL — the day became a night shift. Its clock-out is sitting on its own
     * next-day row, so the night reads as an arrival with no departure and the
     * morning after as a second, impossible arrival (10:57 PM and 7:03 AM on
     * two rows, both zero hours). The punch is pulled back onto the night, but
     * only when the night row holds exactly ONE punch (two already make a day,
     * none means the shift never started) and the next day cannot plausibly own
     * it — that day is a rest day, or the punch falls before its own shift's
     * start with early-arrival grace. Anything ambiguous is left alone: a
     * wrongly merged punch is worse than an unmerged one, since it pays one
     * day's hours on another.
     *
     * PUSH — the day stopped being a night shift. Its after-midnight punch
     * stays put and the row reads as one impossible day: on an 8AM–4PM shift,
     * in at 10:57 PM (late 14.95) and out at 7:03 AM (OT 15.06), work hours 0.
     * A day shift CAN legitimately run past midnight on overtime, so the same
     * 12h ceiling decides — within it the punch is that day's OT and stays;
     * beyond it, it goes back to its own calendar date. The destination row is
     * opened if it does not exist, in the source row's own batch and only when
     * that batch's cutoff covers the date; a punch with nowhere safe to go
     * stays where it is rather than being filed into the wrong payroll period.
     *
     * Final-approved batches (status 2) are never touched on either side —
     * those figures are locked and paid. Writes straight away (the caller's
     * transaction covers it) and puts every row it touched into $rows, so the
     * caller re-derives their figures too.
     */
    private function repairPunchDates(array &$rows): void
    {
        if (!$rows) return;

        $cols = "d.id, d.employee_id, d.date_time, d.work_hours, d.overtime, d.undertime,
                 d.late, d.nsd_hours, d.day_type, d.status, d.logs,
                 d.schedule_id, d.day_hours, d.is_rest_day,
                 d.sched_start, d.sched_end, d.sched_break, d.sched_graveyard";

        // Where each row sits, so a neighbour already in the set is patched in
        // place instead of being fetched — and written — a second time.
        $index = [];
        foreach ($rows as $i => $r) {
            $index[(int) $r['employee_id'] . '|' . date('Y-m-d', strtotime($r['date_time']))] = $i;
        }

        $setLogs = $this->db->prepare("UPDATE DTR_details SET logs = ? WHERE id = ?");
        // Only fills a blank note — an admin's own note must never be overwritten.
        $setNote = $this->db->prepare("UPDATE DTR_details SET notes = ? WHERE id = ? AND (notes IS NULL OR notes = '')");

        $entries = function ($json) {
            $out = [];
            foreach ((json_decode((string) $json, true) ?: []) as $lg) {
                $t = strtotime($lg['dateTime'] ?? '');
                if ($t !== false) $out[] = ['t' => $t, 'lg' => $lg];
            }
            usort($out, function ($a, $b) { return $a['t'] <=> $b['t']; });
            return $out;
        };
        $encode = function (array $es) {
            usort($es, function ($a, $b) { return $a['t'] <=> $b['t']; });
            return json_encode(array_map(function ($e) { return $e['lg']; }, $es));
        };

        // The row for one employee-day: from the working set when it is already
        // there, else off the database. Returns [position|null, row|null].
        $rowAt = function ($eid, $d) use (&$rows, &$index, $cols) {
            $key = $eid . '|' . $d;
            if (isset($index[$key])) return [$index[$key], $rows[$index[$key]]];
            $q = $this->db->query(
                "SELECT $cols FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
                 WHERE d.employee_id = " . (int) $eid . "
                   AND d.date_time = '" . $this->db->real_escape_string($d) . "'
                   AND b.status <> 2 ORDER BY d.id DESC LIMIT 1"
            );
            return [null, $q ? $q->fetch_assoc() : null];
        };

        // Open the row a pushed-back punch needs, in the SOURCE row's batch —
        // and only when that batch's cutoff actually covers the date. Guessing a
        // batch for a date outside it would file the day in the wrong payroll
        // period, which is worse than leaving the punch where it is.
        $openRow = function ($eid, $srcId, $d) use ($cols) {
            $b = $this->db->query(
                "SELECT d.ddtr_id, b.date_from, b.date_to, b.status
                 FROM DTR_details d INNER JOIN DTR b ON b.id = d.ddtr_id
                 WHERE d.id = " . (int) $srcId . " LIMIT 1"
            );
            $b = $b ? $b->fetch_assoc() : null;
            if (!$b || (int) $b['status'] === 2) return null;
            if ($d < $b['date_from'] || $d > $b['date_to']) return null;

            $ins = $this->db->prepare(
                "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs,
                                          attendance_type, day_type, nsd_hours, is_complete, status)
                 VALUES (?, ?, ?, 0, '[]', 'biometric', 'regular', 0, 0, 0)"
            );
            $bid = (int) $b['ddtr_id'];
            $e   = (int) $eid;
            $ins->bind_param('iis', $bid, $e, $d);
            if (!$ins->execute()) throw new Exception('Opening row for ' . $d . ': ' . $ins->error);

            $q = $this->db->query("SELECT $cols FROM DTR_details d WHERE d.id = " . (int) $this->db->insert_id);
            return $q ? $q->fetch_assoc() : null;
        };

        // Put a row back into the working set so the caller re-derives it too.
        $attach = function ($pos, array $row) use (&$rows, &$index) {
            if ($pos !== null) { $rows[$pos] = $row; return; }
            $index[(int) $row['employee_id'] . '|' . date('Y-m-d', strtotime($row['date_time']))] = count($rows);
            $rows[] = $row;
        };

        $write = function ($id, $json) use ($setLogs) {
            $i = (int) $id;
            $setLogs->bind_param('si', $json, $i);
            if (!$setLogs->execute()) throw new Exception('Row ' . $i . ': ' . $setLogs->error);
        };
        $note = function ($id, $text) use ($setNote) {
            $i = (int) $id;
            $setNote->bind_param('si', $text, $i);
            $setNote->execute();
        };

        // $rows grows as neighbours are attached, so walk it by position.
        for ($i = 0; $i < count($rows); $i++) {
            $eid  = (int) $rows[$i]['employee_id'];
            $date = date('Y-m-d', strtotime($rows[$i]['date_time']));

            $sched = resolve_employee_schedule($this->db, $eid, $date);
            if (!$sched) continue;

            // Same overnight test as ingestion and dtr_compute_day: the flag, or
            // an end time that wraps past midnight without the flag being ticked.
            $overnight = $sched['is_graveyard'] || $sched['end_time'] <= $sched['start_time'];
            $end       = strtotime($date . ' ' . $sched['end_time']);
            if ($overnight) $end = strtotime('+1 day', $end);
            $ceiling   = $end + 12 * 3600;      // the OT ceiling ingestion allows

            $own = $entries($rows[$i]['logs']);

            // ── PUSH: punches stranded past this day's shift + OT ceiling ──
            if (!$overnight) {
                $keep = $push = [];
                foreach ($own as $e) {
                    if ($e['t'] > $ceiling) $push[date('Y-m-d', $e['t'])][] = $e;
                    else                    $keep[] = $e;
                }
                if (!$push) continue;

                $landed = [];
                foreach ($push as $tgtDate => $es) {
                    [$pos, $trow] = $rowAt($eid, $tgtDate);
                    if (!$trow) $trow = $openRow($eid, $rows[$i]['id'], $tgtDate);
                    if (!$trow) { $keep = array_merge($keep, $es); continue; }  // nowhere safe to file it

                    $json = $encode(array_merge($entries($trow['logs']), $es));
                    $write($trow['id'], $json);
                    $trow['logs'] = $json;
                    $attach($pos, $trow);
                    $landed[] = $tgtDate;
                }
                if (!$landed) continue;

                $json = $encode($keep);
                $write($rows[$i]['id'], $json);
                $rows[$i]['logs'] = $json;
                if (!$keep) {
                    $note($rows[$i]['id'], 'Punch moved to ' . date('M j', strtotime($landed[0])) . ' — filed on its own day');
                }
                continue;
            }

            // ── PULL: a night shift left open, its clock-out on the next row ──
            if (count($own) !== 1) continue;
            if ($own[0]['t'] >= $end) continue;      // lone punch is already past the shift

            $next = date('Y-m-d', strtotime('+1 day', strtotime($date)));
            [$pos, $nrow] = $rowAt($eid, $next);
            if (!$nrow) continue;

            $nlogs = $entries($nrow['logs']);
            if (!$nlogs) continue;

            // Can the next day own the punch itself? A rest day cannot, and
            // neither can a shift that had not started yet.
            $nsched = resolve_employee_schedule($this->db, $eid, $next);
            if ($nsched !== null && isset($nsched['day_is_rest'])) {
                $nrest = (int) $nsched['day_is_rest'];
            } else {
                $csv   = (string) ($nsched['rest_days'] ?? '');
                $nrest = ($csv !== '' && in_array((int) date('w', strtotime($next)),
                    array_map('intval', explode(',', $csv)), true)) ? 1 : 0;
            }
            $nstart = ($nsched && !$nrest)
                ? strtotime($next . ' ' . $nsched['start_time']) - dtr_early_grace_hours() * 3600
                : null;

            $keep = $move = [];
            foreach ($nlogs as $e) {
                $ownable = $nstart !== null && $e['t'] >= $nstart;
                if (!$ownable && $e['t'] > $own[0]['t'] && $e['t'] <= $ceiling) $move[] = $e;
                else $keep[] = $e;
            }
            if (!$move) continue;

            $nightJson = $encode(array_merge($own, $move));
            $nextJson  = $encode($keep);
            $write($rows[$i]['id'], $nightJson);
            $write($nrow['id'], $nextJson);

            $rows[$i]['logs'] = $nightJson;
            $nrow['logs']     = $nextJson;

            // Say where the punch went, so the day it left doesn't just read as
            // an unexplained blank on the sheet. varchar(100) — keep it short.
            if (!$keep) $note($nrow['id'], 'Punch moved to ' . date('M j', strtotime($date)) . ' — night shift clock-out');

            $attach($pos, $nrow);
        }
    }

    // Best-effort audit row for a recompute run — who ran it, its scope, and
    // what moved. Recomputes rewrite figures in bulk, so disputes need a
    // trail; a missing log table must never fail the recompute itself.
    private function logRecompute($ddtr_id, $employee_id, $scope, $scanned, $changed, $repending)
    {
        try {
            $ranBy = (int)($_SESSION['login_id'] ?? 0) ?: null;
            $stmt = $this->db->prepare(
                "INSERT INTO dtr_recompute_log (ddtr_id, employee_id, ran_by, scope, scanned, changed, repending)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('iiisiii', $ddtr_id, $employee_id, $ranBy, $scope, $scanned, $changed, $repending);
            $stmt->execute();
        } catch (\Throwable $e) { /* audit is best-effort */ }
    }

    /**
     * Message an employee about one specific attendance record — asks for an
     * explanation without forcing a disapproval. Stored per record
     * (dtr_messages) and delivered as a portal bell notification + push.
     */
    function message_dtr_record()
    {
        $role = (int)($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];

        $rec_id  = (int)($_POST['id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if (!$rec_id || $message === '') return ['result' => false, 'message' => 'A message is required.'];
        if (mb_strlen($message) > 500)   return ['result' => false, 'message' => 'Message is too long (max 500 characters).'];

        $rec = $this->db->query("SELECT id, employee_id, date_time FROM DTR_details WHERE id = $rec_id")->fetch_assoc();
        if (!$rec) return ['result' => false, 'message' => 'Record not found'];

        $uid    = (int)($_SESSION['login_id'] ?? 0) ?: null;
        $rid    = (int)$rec['id'];
        $eid    = (int)$rec['employee_id'];
        $rdate  = $rec['date_time'];
        $stmt = $this->db->prepare("INSERT INTO dtr_messages (dtr_detail_id, employee_id, date_time, message, sent_by, sender_type) VALUES (?,?,?,?,?,'admin')");
        $stmt->bind_param('iissi', $rid, $eid, $rdate, $message, $uid);
        if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];

        $dateLbl = date('M j, Y', strtotime($rdate));
        $this->notifyEmployee(
            $eid,
            'Question about your attendance',
            "Support asks about your $dateLbl attendance: \u{201C}$message\u{201D}",
            'ri-chat-3-line',
            'warning',
            'employee-portal.php?tab=attendance&rec=' . $rid . '&date=' . rawurlencode($rdate)
        );

        $by = '';
        if ($uid) {
            $u = $this->db->query("SELECT name FROM users WHERE id = $uid")->fetch_assoc();
            $by = $u['name'] ?? '';
        }
        return ['result' => true, 'message' => 'Message sent', 'by' => $by, 'at' => date('M j, g:i A')];
    }

    // ── Internal admin notes on an employee (DTR Documents, admin-only) ──
    // These never reach the employee — separate from dtr_messages (which do).
    // Levels: info (blue), good (green), watch (amber), critical (red).
    function save_dtr_note()
    {
        $role = (int)($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];

        $ddtr_id = (int)($_POST['ddtr_id'] ?? 0);
        $emp_id  = (int)($_POST['employee_id'] ?? 0);
        $level   = (string)($_POST['level'] ?? 'info');
        $note    = trim($_POST['note'] ?? '');
        if (!$ddtr_id || !$emp_id)  return ['result' => false, 'message' => 'Missing employee or batch.'];
        if ($note === '')           return ['result' => false, 'message' => 'A note is required.'];
        if (mb_strlen($note) > 500) return ['result' => false, 'message' => 'Note is too long (max 500 characters).'];
        if (!in_array($level, ['info', 'good', 'watch', 'critical'], true)) $level = 'info';

        $uid = (int)($_SESSION['login_id'] ?? 0) ?: null;
        $stmt = $this->db->prepare("INSERT INTO dtr_admin_notes (ddtr_id, employee_id, level, note, created_by) VALUES (?,?,?,?,?)");
        $stmt->bind_param('iissi', $ddtr_id, $emp_id, $level, $note, $uid);
        if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];

        $by = '';
        if ($uid) {
            $u = $this->db->query("SELECT name FROM users WHERE id = $uid")->fetch_assoc();
            $by = $u['name'] ?? '';
        }
        return ['result' => true, 'id' => $this->db->insert_id, 'by' => $by, 'at' => date('M j, g:i A')];
    }

    function delete_dtr_note()
    {
        $role = (int)($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing id'];
        $stmt = $this->db->prepare("DELETE FROM dtr_admin_notes WHERE id = ?");
        $stmt->bind_param('i', $id);
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Deleted'];
    }

    // ── Fingerprint similarity reports (scanner look-alike audit) ──────────
    function review_similarity_report()
    {
        $role = (int)($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];
        $id   = (int)($_POST['id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if (!$id) return ['result' => false, 'message' => 'Missing id'];
        if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);
        $by = (int)($_SESSION['login_id'] ?? 0) ?: null;
        $stmt = $this->db->prepare("UPDATE biometric_similarity_reports
                                    SET reviewed_at = NOW(), reviewed_by = ?, review_note = ?
                                    WHERE id = ?");
        $stmt->bind_param('isi', $by, $note, $id);
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Marked reviewed'];
    }

    function delete_similarity_report()
    {
        $role = (int)($_SESSION['login_role'] ?? 0);
        if ($role === 6) return ['result' => false, 'message' => 'Not authorized'];
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return ['result' => false, 'message' => 'Missing id'];
        $stmt = $this->db->prepare("DELETE FROM biometric_similarity_reports WHERE id = ?");
        $stmt->bind_param('i', $id);
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Deleted'];
    }

    function save_calendar_event()
    {
        $id          = (int) ($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $start       = trim($_POST['start_date'] ?? '');
        $end         = trim($_POST['end_date'] ?? '');
        $type        = (int) ($_POST['type'] ?? 1);          // 1 holiday, 2 activity
        $blocks      = isset($_POST['blocks_leave']) ? (int) $_POST['blocks_leave'] : ($type === 1 ? 1 : 0);
        $note        = trim($_POST['note'] ?? '');
        $created_by  = $_SESSION['login_id'] ?? null;

        if ($title === '' || $start === '') return ['result' => false, 'message' => 'Title and start date are required.'];
        $ts_s = strtotime($start);
        if ($ts_s === false) return ['result' => false, 'message' => 'Invalid start date.'];
        $start = date('Y-m-d', $ts_s);
        $end_sql = 'NULL';
        if ($end !== '') {
            $ts_e = strtotime($end);
            if ($ts_e === false) return ['result' => false, 'message' => 'Invalid end date.'];
            if ($ts_e < $ts_s) return ['result' => false, 'message' => 'End date cannot be before start date.'];
            $end_sql = "'" . date('Y-m-d', $ts_e) . "'";
        }
        $color = $type === 1 ? '#dc3545' : ($type === 3 ? '#fd7e14' : '#0d6efd');

        if ($id === 0) {
            if ($end_sql === 'NULL') {
                $stmt = $this->db->prepare("INSERT INTO calendar_events (title, start_date, end_date, type, blocks_leave, color, note, created_by) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssiissi', $title, $start, $type, $blocks, $color, $note, $created_by);
            } else {
                $endv = trim($end_sql, "'");
                $stmt = $this->db->prepare("INSERT INTO calendar_events (title, start_date, end_date, type, blocks_leave, color, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssiissi', $title, $start, $endv, $type, $blocks, $color, $note, $created_by);
            }
        } else {
            if ($end_sql === 'NULL') {
                $stmt = $this->db->prepare("UPDATE calendar_events SET title=?, start_date=?, end_date=NULL, type=?, blocks_leave=?, color=?, note=? WHERE id=?");
                $stmt->bind_param('ssiissi', $title, $start, $type, $blocks, $color, $note, $id);
            } else {
                $endv = trim($end_sql, "'");
                $stmt = $this->db->prepare("UPDATE calendar_events SET title=?, start_date=?, end_date=?, type=?, blocks_leave=?, color=?, note=? WHERE id=?");
                $stmt->bind_param('sssiissi', $title, $start, $endv, $type, $blocks, $color, $note, $id);
            }
        }

        if ($stmt->execute()) {
            return ['result' => true, 'message' => $id === 0 ? 'Event added.' : 'Event updated.'];
        }
        return ['result' => false, 'message' => $stmt->error];
    }

    function delete_calendar_event()
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($this->db->query("DELETE FROM calendar_events WHERE id = $id")) {
            return ['result' => true, 'message' => 'Event deleted.'];
        }
        return ['result' => false, 'message' => $this->db->error];
    }

    /**
     * What a calendar event's dates touch in payroll, so the delete confirmation
     * can name the runs at risk instead of warning in the abstract.
     *
     * Holiday dates are read straight from this table by calculate_payroll: they
     * set each employee's legal/special holiday day count and they keep a monthly
     * employee from being marked absent for a paid non-working day. Removing one
     * therefore CHANGES PAY the next time an overlapping run is recalculated, and
     * any overlapping run already locked was paid with it.
     *
     * Read-only. Activities (type 2) never reach the calculation, so they report
     * no impact at all.
     */
    function calendar_event_impact()
    {
        $id  = (int) ($_POST['id'] ?? 0);
        $res = $this->db->query(
            "SELECT id, title, type, start_date, end_date FROM calendar_events WHERE id = $id"
        );
        $ev = $res ? $res->fetch_assoc() : null;
        if (!$ev) return ['result' => false, 'message' => 'Event not found.'];

        $isHoliday = in_array((int) $ev['type'], [1, 3], true);
        $out = [
            'result'     => true,
            'title'      => $ev['title'],
            'is_holiday' => $isHoliday,
            'payrolls'   => [],
            'locked'     => 0,
        ];
        if (!$isHoliday) return $out;

        $from = $ev['start_date'];
        $to   = $ev['end_date'] ?: $ev['start_date'];

        // Any payroll period overlapping the event's dates. with_holiday counts
        // the employees in that run currently carrying holiday days — the pay
        // that would disappear on the next recalculate.
        $q = $this->db->prepare(
            "SELECT p.id, p.ref_no, p.date_from, p.date_to, p.status,
                    COALESCE(SUM((pi.legal_holiday + pi.special_holiday) > 0), 0) AS with_holiday
               FROM payroll p
               LEFT JOIN payroll_items pi ON pi.payroll_id = p.id
              WHERE p.date_from <= ? AND p.date_to >= ?
           GROUP BY p.id, p.ref_no, p.date_from, p.date_to, p.status
           ORDER BY p.date_from DESC"
        );
        if ($q) {
            $q->bind_param('ss', $to, $from);
            $q->execute();
            $r = $q->get_result();
            while ($p = $r->fetch_assoc()) {
                $locked = (int) $p['status'] === 2;
                if ($locked) $out['locked']++;
                $out['payrolls'][] = [
                    'ref_no' => $p['ref_no'],
                    'period' => date('M d', strtotime($p['date_from'])) . ' – ' . date('M d, Y', strtotime($p['date_to'])),
                    'locked' => $locked,
                    'paid'   => (int) $p['with_holiday'],
                ];
            }
        }
        return $out;
    }

    // Events as a FullCalendar-friendly array (optionally bounded by ?start/?end).
    function get_calendar_events()
    {
        $events = [];
        $year = date('Y');
        $res = $this->db->query("SELECT * FROM calendar_events WHERE YEAR(start_date) = $year ORDER BY start_date ASC");
        if ($res) while ($r = $res->fetch_assoc()) {
            // FullCalendar treats `end` as exclusive for all-day events → add 1 day.
            $end = $r['end_date'] ?: $r['start_date'];
            $end_excl = date('Y-m-d', strtotime($end . ' +1 day'));
            $events[] = [
                'id'    => $r['id'],
                'title' => ($r['type'] == 1 ? '🛑 ' : '📌 ') . $r['title'],
                'start' => $r['start_date'],
                'end'   => $end_excl,
                'color' => $r['color'],
                'allDay' => true,
                'extendedProps' => [
                    'type'         => (int) $r['type'],
                    'blocks_leave' => (int) $r['blocks_leave'],
                    'note'         => $r['note'],
                    'raw_title'    => $r['title'],
                    'raw_start'    => $r['start_date'],
                    'raw_end'      => $r['end_date'],
                ],
            ];
        }
        return $events;
    }

    // Returns ['Y-m-d' => 'Holiday title', ...] for every leave-blocking calendar day.
    private function getBlockedDates()
    {
        $blocked = [];
        $res = $this->db->query("SELECT title, start_date, end_date FROM calendar_events WHERE blocks_leave = 1");
        if ($res) while ($r = $res->fetch_assoc()) {
            $d = strtotime($r['start_date']);
            $e = strtotime($r['end_date'] ?: $r['start_date']);
            while ($d <= $e) {
                $blocked[date('Y-m-d', $d)] = $r['title'];
                $d = strtotime('+1 day', $d);
            }
        }
        return $blocked;
    }

    // Leave eligibility: only Regular / Executive classifications earn leave credits.
    private function isLeaveEligible($employee_id)
    {
        $employee_id = (int) $employee_id;
        $r = $this->db->query("
            SELECT UPPER(cl.clasification) AS c, e.leave_override
            FROM employee e LEFT JOIN clasification cl ON cl.id = e.clasification_id
            WHERE e.id = $employee_id
        ")->fetch_assoc();
        return $r && leave_eligibility_from($r['c'], $r['leave_override']);
    }

    // Small helper: employee name + leave type + duration for notification text.
    private function leaveInfo($leave_id)
    {
        $leave_id = (int) $leave_id;
        $r = $this->db->query("
            SELECT CONCAT(e.firstname,' ',e.lastname) AS emp, lt.name AS type, lr.duration AS dur
            FROM leave_requests lr
            JOIN employee e ON e.id = lr.employee_id
            JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.id = $leave_id
        ")->fetch_assoc();
        return $r ?: ['emp' => 'Employee', 'type' => 'leave', 'dur' => 0];
    }

    /* ──────────────────────────────────────────────────────────────
     * Firebase Cloud Messaging (browser push, works with the site closed)
     * ────────────────────────────────────────────────────────────── */

    // Register/refresh the FCM token of the logged-in admin's browser.
    function save_fcm_token()
    {
        $uid = (int) ($_SESSION['login_id'] ?? 0);
        $token = trim($_POST['token'] ?? '');
        if ($uid <= 0 || $token === '' || strlen($token) > 500) {
            return 0;
        }
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        // Single-device, single-recipient policy. Registering this browser evicts:
        //  a) the admin's tokens on OTHER devices (and rotated tokens on this one),
        //     so each push lands exactly once, on the most recent login; and
        //  b) any EMPLOYEE registration of this same browser token — a browser
        //     belongs to whoever logged in last, so a machine an employee once
        //     used never keeps receiving that employee's pushes after an admin
        //     takes it over. users.id and employee.id overlap, so recipient_type
        //     is always part of the match — never user_id alone.
        $del = $this->db->prepare(
            "DELETE FROM fcm_tokens
             WHERE (user_id = ? AND recipient_type = 'user' AND token <> ?)
                OR (token = ? AND recipient_type = 'employee')"
        );
        $del->bind_param('iss', $uid, $token, $token);
        $del->execute();
        $stmt = $this->db->prepare(
            "INSERT INTO fcm_tokens (user_id, recipient_type, token, user_agent) VALUES (?, 'user', ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
                                     user_agent = VALUES(user_agent), last_seen = NOW()"
        );
        $stmt->bind_param('iss', $uid, $token, $ua);
        return $stmt->execute() ? 1 : 0;
    }

    // Best-effort browser push to every registered admin. Must never break the
    // caller — attendance saves fine even if FCM is down or not configured.
    private function pushAdminBrowsers($title, $body, $link = 'index.php', $tag = 'comc-payroll')
    {
        try {
            require_once __DIR__ . '/fcm.php';
            return fcm_push_admins($this->db, $title, $body, $link, $tag);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* ──────────────────────────────────────────────────────────────
     * Notifications (per-user, with mark-read)
     * ────────────────────────────────────────────────────────────── */

    // Insert one notification for a single user.
    private function notify($user_id, $title, $message, $icon = 'ri-notification-3-line', $color = 'primary', $link = null)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return;
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link) VALUES (?, 'user', ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssss', $user_id, $title, $message, $icon, $color, $link);
        $stmt->execute();
    }

    // Insert the same notification for every active user holding a given role.
    private function notifyRole($role, $title, $message, $icon = 'ri-notification-3-line', $color = 'primary', $link = null)
    {
        $role = (int) $role;
        $res = $this->db->query("SELECT id FROM users WHERE role = $role AND status = 1");
        if ($res) while ($u = $res->fetch_assoc()) {
            $this->notify($u['id'], $title, $message, $icon, $color, $link);
        }
        // Mirror to that role's browsers as a push (best-effort, never fatal) so
        // reviewers get leave / attendance-request alerts with the site closed.
        try {
            require_once __DIR__ . '/fcm.php';
            fcm_push_role($this->db, $role, $title, $message, $link ?: 'index.php');
        } catch (\Throwable $e) { /* ignore */ }
    }

    // Like notifyRole, but only reaches users of that role who are responsible
    // for the given employee's department — the Supervisor / Department Head of
    // that department, plus any unscoped (NULL / 0 department) reviewer of that
    // role such as HR. Keeps a department's leave alerts out of other
    // departments' bells.
    private function notifyRoleForEmployee($role, $employee_id, $title, $message, $icon = 'ri-notification-3-line', $color = 'primary', $link = null)
    {
        $role        = (int) $role;
        $employee_id = (int) $employee_id;

        $dept = 0;
        $er = $this->db->query("SELECT department_id FROM employee WHERE id = $employee_id");
        if ($er && ($e = $er->fetch_assoc())) $dept = (int) $e['department_id'];

        $res = $this->db->query(
            "SELECT id FROM users
             WHERE role = $role AND status = 1
               AND (department_id = $dept OR department_id IS NULL OR department_id = 0)"
        );
        if ($res) while ($u = $res->fetch_assoc()) {
            $this->notify($u['id'], $title, $message, $icon, $color, $link);
        }
        // Best-effort browser push to the whole role (never fatal).
        try {
            require_once __DIR__ . '/fcm.php';
            fcm_push_role($this->db, $role, $title, $message, $link ?: 'index.php');
        } catch (\Throwable $e) { /* ignore */ }
    }

    // Employee-facing notification (portal bell). Same table as staff notifications,
    // distinguished by recipient_type since users.id and employee.id sequences overlap.
    private function notifyEmployee($employee_id, $title, $message, $icon = 'ri-notification-3-line', $color = 'primary', $link = null)
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0) return;
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link) VALUES (?, 'employee', ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssss', $employee_id, $title, $message, $icon, $color, $link);
        $stmt->execute();
        // Mirror to the employee's browser as a push (best-effort, never fatal).
        try {
            require_once __DIR__ . '/fcm.php';
            fcm_push_employee($this->db, $employee_id, $title, $message, $link ?: 'employee-portal.php');
        } catch (\Throwable $e) { /* ignore */
        }
    }

    /**
     * "You have hours to file" nudge for ONE date, sent once.
     *
     * Rest-day work is the case that needs it: with
     * pay_settings.rest_day_auto_authorize off, NOTHING on a rest-day record is
     * approved or paid until the employee files for it — and an employee who
     * never opens the portal has no way of learning that before the period
     * closes. Overtime gets the same nudge, where the stake is only the OT
     * itself.
     *
     * ot_request_limit() decides whether there is anything to file at all, so
     * this can never nag about a day the form would refuse. Sent at most once
     * per date: the link carries the date and doubles as the dedupe key.
     */
    private function notifyUnfiledHours($employee_id, $date)
    {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0 || !$date) return;

        $lim = ot_request_limit($this->db, $employee_id, $date);
        if (!$lim['allowed']) return;

        $ymd  = date('Y-m-d', strtotime($date));
        $esc  = $this->db->real_escape_string($ymd);
        $has  = $this->db->query(
            "SELECT 1 FROM attendance_requests
             WHERE employee_id = $employee_id AND request_date = '$esc'
               AND request_type IN ('overtime', 'rest_day') AND status IN (0, 1) LIMIT 1"
        );
        if ($has && $has->num_rows) return;          // already filed — nothing to nudge

        $link = 'employee-portal.php?tab=att-requests&file=' . $ymd;
        $sent = $this->db->query(
            "SELECT 1 FROM notifications
             WHERE user_id = $employee_id AND recipient_type = 'employee'
               AND link = '" . $this->db->real_escape_string($link) . "' LIMIT 1"
        );
        if ($sent && $sent->num_rows) return;        // already told them once

        $when = date('M d, Y', strtotime($ymd));
        if ($lim['rest_day']) {
            $title = 'File your rest-day work';
            $msg   = "You worked on your day off ($when) — {$lim['max_hours']} hrs. "
                   . 'File it now: your attendance for that date stays unapproved, and unpaid, until this is approved.';
        } else {
            $title = 'File your overtime';
            $msg   = "Your scans for $when show {$lim['max_hours']} hrs past your shift end. "
                   . 'Overtime is only paid once it is filed and approved.';
        }
        $this->notifyEmployee($employee_id, $title, $msg, 'ri-timer-flash-line', 'warning', $link);
    }

    // Notify every employee returned by $employeeIdSelect (a SELECT yielding one
    // employee_id column) with one INSERT ... SELECT instead of a per-employee
    // round trip — sending a large batch for review used to fire hundreds of
    // sequential INSERTs. Returns the number of notifications inserted.
    private function notifyEmployeesFromQuery($employeeIdSelect, $title, $message, $icon = 'ri-notification-3-line', $color = 'primary', $link = null)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (user_id, recipient_type, title, message, icon, color, link)
             SELECT emp.employee_id, 'employee', ?, ?, ?, ?, ? FROM ($employeeIdSelect) emp"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('sssss', $title, $message, $icon, $color, $link);
        $stmt->execute();
        return $stmt->affected_rows > 0 ? $stmt->affected_rows : 0;
    }

    // Recent notifications + unread count for the logged-in staff user.
    // Paginated so the dropdown loads fast and pulls more only as the user scrolls
    // (infinite scroll), instead of rendering the entire notification history at once.
    function get_notifications()
    {
        $uid = (int) ($_SESSION['login_id'] ?? 0);
        if ($uid <= 0) return ['result' => false, 'items' => [], 'unread' => 0, 'has_more' => false];

        $limit  = isset($_POST['limit'])  ? max(1, min(50, (int) $_POST['limit']))  : 15;
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

        $items = [];
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id = ? AND recipient_type = 'user' ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('iii', $uid, $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $items[] = $r;

        $cnt   = $this->db->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $uid AND recipient_type = 'user' AND is_read = 0")->fetch_assoc();
        $total = $this->db->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $uid AND recipient_type = 'user'")->fetch_assoc();

        return [
            'result'   => true,
            'items'    => $items,
            'unread'   => (int) $cnt['c'],
            'has_more' => ($offset + count($items)) < (int) $total['c'],
        ];
    }

    function mark_notification_read()
    {
        $uid = (int) ($_SESSION['login_id'] ?? 0);
        $id  = (int) ($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND recipient_type = 'user'");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        return ['result' => true];
    }

    function mark_all_notifications_read()
    {
        $uid = (int) ($_SESSION['login_id'] ?? 0);
        $this->db->query("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = $uid AND recipient_type = 'user' AND is_read = 0");
        return ['result' => true];
    }

    function import_employeeOLD()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $allowedExt = ['xls', 'xlsx', 'csv'];
        $fileExt = pathinfo($_FILES['excelFile']['name'], PATHINFO_EXTENSION);

        if (!in_array($fileExt, $allowedExt)) {
            die("Invalid file type. Only Excel files are allowed.");
        }

        $file = $_FILES['excelFile']['tmp_name'];
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();
        if (count($data) > 1) { // Ensure there is more than just the header
            array_shift($data); // Remove header row
        }
        $this->db->begin_transaction();
        $stmtCheckPosition = $this->db->prepare("SELECT id FROM position WHERE LOWER(name) = LOWER(?)");
        $stmtInsertPosition = $this->db->prepare("INSERT INTO position (name) VALUES (?)");
        $stmtInsert =  $this->db->prepare("INSERT INTO employee 
        (employee_no, employee_code, firstname, middlename, lastname, position_id, salary, basic_pay, status, ot_rate, isAutoDeduct, weekly_payroll, clasification_id, sss_fund, allowance_rate, sss_no, ph_no, hdmf_no, tin_no, ext, bday) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? ,? ,? ,?, ? , ? )");

        try {


            foreach ($data as $row) {
                $employee_code = mt_rand(100000000000, 999999999999);
                $status = 1;
                $e_num = date('Y') . '-' . mt_rand(1, 99999);
                $clasification_id = isset($row[0]) ? intval(trim($row[0])) : 1;
                $firstname = trim($row[1]);
                $lastname = trim($row[3]);
                if (empty($firstname) || empty($lastname)) {
                    continue;
                }
                $middlename = trim($row[2]);
                $ext = "";
                $position_name =  trim($row[4]);
                $basic_pay = floatval(preg_replace('/[^0-9.]/', '', $row[5]));
                $salary = floatval(preg_replace('/[^0-9.]/', '', $row[6]));
                $ot_rate = floatval(preg_replace('/[^0-9.]/', '', $row[7]));
                $allowance_rate = floatval(preg_replace('/[^0-9.]/', '', $row[8]));
                $sss_fund = floatval(preg_replace('/[^0-9.]/', '', $row[9]));
                $weekly_payroll = intval($row[10]);
                $isAutoDeduct = intval($row[11]);
                $sss = floatval(preg_replace('/[^0-9.]/', '', $row[12]));;
                $sss_loan = floatval(preg_replace('/[^0-9.]/', '', $row[13]));
                $phic = floatval(preg_replace('/[^0-9.]/', '', $row[14]));
                $hdmf = floatval(preg_replace('/[^0-9.]/', '', $row[15]));
                $hdmf_loan = floatval(preg_replace('/[^0-9.]/', '', $row[16]));
                $bday = trim($row[17]);
                $ph_no = trim($row[18]);
                $hdmf_no = trim($row[19]);
                $sss_no = trim($row[20]);
                $ppe = floatval(preg_replace('/[^0-9.]/', '', $row[21]));
                $cash_bond = floatval(preg_replace('/[^0-9.]/', '', $row[22]));
                $penalty = floatval(preg_replace('/[^0-9.]/', '', $row[23]));
                $cash_advance = floatval(preg_replace('/[^0-9.]/', '', $row[24]));
                $tin_no = "";


                // 🔹 CHECK IF EMPLOYEE EXISTS (case-sensitive)
                $stmtCheckEmployee = $this->db->prepare("SELECT id FROM employee WHERE  LOWER(firstname) = LOWER(?)  AND  LOWER(lastname) = LOWER(?)  AND  LOWER(middlename) = LOWER(?) ");
                $stmtCheckEmployee->bind_param("sss", $firstname, $lastname, $middlename);
                $stmtCheckEmployee->execute();
                $stmtCheckEmployee->store_result();

                if ($stmtCheckEmployee->num_rows > 0) {
                    echo "Skipping duplicate employee: $firstname $lastname $middlename \n"; // Debugging message
                    $stmtCheckEmployee->free_result();
                    continue; // Skip this row and move to the next
                }
                $stmtCheckEmployee->free_result();

                // 🔹 CHECK IF POSITION EXISTS (case-insensitive)
                $position_id = null; // Reset before each check
                $stmtCheckPosition->bind_param("s", $position_name);
                $stmtCheckPosition->execute();
                $stmtCheckPosition->store_result(); // Ensure previous results don’t interfere

                if ($stmtCheckPosition->num_rows > 0) {
                    $stmtCheckPosition->bind_result($position_id);
                    $stmtCheckPosition->fetch();
                } else {
                    // 🔹 INSERT NEW POSITION
                    $stmtInsertPosition->bind_param("s", $position_name);
                    $stmtInsertPosition->execute();
                    $position_id = $this->db->insert_id; // Get new position ID
                }
                $stmtCheckPosition->free_result(); // Free result set to avoid conflicts
                $stmtInsert->bind_param("sssssssssssssssssssss", $e_num, $employee_code, $firstname, $middlename, $lastname, $position_id, $salary, $basic_pay, $status, $ot_rate, $isAutoDeduct, $weekly_payroll, $clasification_id, $sss_fund, $allowance_rate, $sss_no, $ph_no, $hdmf_no, $tin_no, $ext, $bday);
                $stmtInsert->execute();
                if ($stmtInsert->affected_rows > 0) {
                    $employee_id =  $this->db->insert_id;
                    // Insert contributions for SSS, PHIC, HDMF
                    $contributions = [
                        ['id' => 1, 'amount' => $sss],
                        ['id' => 2, 'amount' => $phic],
                        ['id' => 3, 'amount' => $hdmf]
                    ];
                    $query = "INSERT INTO employee_contributions (employee_id, contribution_id, amount, payroll_type) VALUES (?, ?, ?, ?)";
                    $stmt = $this->db->prepare($query);
                    foreach ($contributions as $contribution) {
                        $stmt->bind_param("ssss", $employee_id, $contribution['id'], $contribution['amount'], $payroll_type);
                        $payroll_type = 1;
                        $stmt->execute();
                        if ($stmt->affected_rows <= 0) {
                            throw new Exception("Failed to insert contribution.");
                        }
                    }

                    // loans
                    if ($sss_loan > 0) {
                        $loan_status = 0;
                        $current_date = date('Y-m-d');
                        $data = " employee_id=$employee_id ";
                        $data .= ", loan_date='$current_date' ";
                        $data .= ", loan_amount = $sss_loan ";
                        $data .= ", loan_status = $loan_status ";
                        $data .= ", loan_type = 1 ";
                        $data .= ", loan_balance = $sss_loan ";
                        $data .= ", damount = $sss_loan ";
                        $this->db->query("INSERT INTO loans SET " . $data);
                    }

                    if ($hdmf_loan > 0) {
                        $loan_status = 0;
                        $current_date = date('Y-m-d');
                        $data = " employee_id=$employee_id ";
                        $data .= ", loan_date='$current_date' ";
                        $data .= ", loan_amount = $hdmf_loan ";
                        $data .= ", loan_status = $loan_status ";
                        $data .= ", loan_type = 2 ";
                        $data .= ", loan_balance = $hdmf_loan ";
                        $data .= ", damount = $hdmf_loan ";
                        $this->db->query("INSERT INTO loans SET " . $data);
                    }

                    //cash bond
                    if ($cash_bond > 0) {
                        $data = " employee_id='$employee_id' ";
                        $data .= ", deduction_id = 1 ";
                        $data .= ", amount = $cash_bond ";
                        $this->db->query("INSERT INTO employee_deductions set " . $data);
                    }


                    //ppe
                    if ($ppe > 0) {
                        $data = " employee_id='$employee_id' ";
                        $data .= ", deduction_id = 2 ";
                        $data .= ", amount = $ppe ";
                        $this->db->query("INSERT INTO employee_deductions set " . $data);
                    }

                    //penalty
                    if ($penalty > 0) {
                        $data = " employee_id='$employee_id' ";
                        $data .= ", deduction_id = 3 ";
                        $data .= ", amount = $penalty ";
                        $this->db->query("INSERT INTO employee_deductions set " . $data);
                    }


                    //ca
                    if ($cash_advance > 0) {
                        $data = " employee_id='$employee_id' ";
                        $data .= ", deduction_id = 4 ";
                        $data .= ", amount = $cash_advance ";
                        $this->db->query("INSERT INTO employee_deductions set " . $data);
                    }
                } else {
                    throw new Exception("Failed to insert employee: " . $stmtInsert->error);
                }
            }

            $this->db->commit();
            echo "saved";
        } catch (Exception $e) {
            // Rollback transaction in case of error
            $this->db->rollback();
            echo "Error: " . $e->getMessage();
        }

        $stmtInsert->close();
    }

    function import_employee()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $allowedExt = ['xls', 'xlsx', 'csv'];
        $fileExt = pathinfo($_FILES['excelFile']['name'], PATHINFO_EXTENSION);

        if (!in_array($fileExt, $allowedExt)) {
            die("Invalid file type. Only Excel files are allowed.");
        }

        $file = $_FILES['excelFile']['tmp_name'];
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();
        if (count($data) > 1) {
            array_shift($data); // Remove header row
        }

        $this->db->begin_transaction();
        $stmtCheckPosition  = $this->db->prepare("SELECT id FROM position WHERE LOWER(name) = LOWER(?)");
        $stmtInsertPosition = $this->db->prepare("INSERT INTO position (name) VALUES (?)");
        $stmtInsert = $this->db->prepare("INSERT INTO employee
    (employee_no, employee_code, firstname, middlename, lastname, position_id, salary, basic_pay, rate_type, status, ot_rate, isAutoDeduct, weekly_payroll, clasification_id, sss_fund, allowance_rate, sss_no, ph_no, hdmf_no, tin_no, ext, bday)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmtUpdate = $this->db->prepare("UPDATE employee SET
    position_id=?, salary=?, basic_pay=?, rate_type=?, ot_rate=?, isAutoDeduct=?, weekly_payroll=?, clasification_id=?, sss_fund=?, allowance_rate=?, sss_no=?, ph_no=?, hdmf_no=?, bday=?, employee_no=?, employee_code=?, ext=?
    WHERE id=?");

        $stmtUpdateContrib = $this->db->prepare("UPDATE employee_contributions SET amount=? WHERE employee_id=? AND contribution_id=?");

        // Lookups for the appended template columns (classification / shift / deduction).
        $stmtCheckClas     = $this->db->prepare("SELECT id FROM clasification WHERE LOWER(clasification) = LOWER(?)");
        $stmtCheckSchedule = $this->db->prepare("SELECT id FROM work_schedules WHERE LOWER(description) = LOWER(?) AND status = 1");
        $stmtCheckDeduct   = $this->db->prepare("SELECT id FROM deductions WHERE LOWER(deduction) = LOWER(?)");

        try {
            $insertCount = 0;
            $updateCount = 0;

            foreach ($data as $row) {
                // Columns N onward are optional: spreadsheets built against the
                // old 13-column layout must keep importing, so every new index
                // is null-coalesced rather than assumed to exist.
                // Reset per row: a leftover id from the previous iteration would
                // attach this row's shift/deduction to the wrong employee.
                $employee_id = 0;
                $employee_code = mt_rand(100000000000, 999999999999);
                $status = 1;
                $e_num = date('Y') . '-' . mt_rand(1, 99999);

                // Classification by name (col N). Unknown/blank falls back to Regular.
                $clasification_id = 1;
                $clasification_name = trim((string) ($row[13] ?? ''));
                if ($clasification_name !== '') {
                    $stmtCheckClas->bind_param("s", $clasification_name);
                    $stmtCheckClas->execute();
                    $stmtCheckClas->store_result();
                    if ($stmtCheckClas->num_rows > 0) {
                        $stmtCheckClas->bind_result($found_clas_id);
                        $stmtCheckClas->fetch();
                        $clasification_id = (int) $found_clas_id;
                    }
                    $stmtCheckClas->free_result();
                }

                // Parse "LASTNAME, FIRSTNAME[ MIDDLENAME]" from a single cell
                $raw_name = trim($row[3]);
                if (strpos($raw_name, ',') !== false) {
                    [$last_part, $rest] = explode(',', $raw_name, 2);
                    $lastname   = trim($last_part);
                    $name_parts = preg_split('/\s+/', trim($rest), 2);
                    $firstname  = $name_parts[0] ?? '';
                    $middlename = $name_parts[1] ?? '';
                } else {
                    $lastname   = $raw_name;
                    $firstname  = trim($row[1]);
                    $middlename = trim($row[2]);
                }

                if (empty($firstname) || empty($lastname)) {
                    continue;
                }

                $ext = "";
                $position_name = trim($row[4]);
                $basic_pay = floatval(preg_replace('/[^0-9.]/', '', $row[10]));
                // Daily Rate (col U) -> salary; Rate Type (col V) -> pay basis.
                // Both are appended columns, so old sheets that lack them fall
                // back to salary 0 / 'daily' rather than erroring.
                $salary = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[20] ?? '')));
                $rate_type = strtolower(trim((string) ($row[21] ?? '')));
                if (!in_array($rate_type, ['daily', 'monthly', 'fixed'], true)) {
                    $rate_type = 'daily';
                }
                $ot_rate = floatval(preg_replace('/[^0-9.]/', '', $row[12]));
                $allowance_rate = floatval(preg_replace('/[^0-9.]/', '', $row[11]));
                $sss_fund = 0;
                $weekly_payroll = 0; // weekly payroll removed — always semi-monthly
                $isAutoDeduct = 0;
                // Contribution AMOUNTS come straight from the sheet (cols O/P/Q);
                // they are deliberately NOT auto-calculated. Blank = 0.
                $sss  = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[14] ?? '')));
                $phic = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[15] ?? '')));
                $hdmf = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[16] ?? '')));
                $sss_loan = 0;
                $hdmf_loan = 0;
                $bday = "";
                $ph_no = trim($row[5]);
                $hdmf_no = trim($row[9]);
                $sss_no = trim($row[7]);
                $ppe = 0;
                $cash_bond = 0;
                $penalty = 0;
                $cash_advance = 0;
                $tin_no = "";

                // 🔹 CHECK IF EMPLOYEE EXISTS
                $stmtCheckEmployee = $this->db->prepare("SELECT id FROM employee WHERE LOWER(firstname) = LOWER(?) AND LOWER(lastname) = LOWER(?) AND LOWER(middlename) = LOWER(?)");
                $stmtCheckEmployee->bind_param("sss", $firstname, $lastname, $middlename);
                $stmtCheckEmployee->execute();
                $stmtCheckEmployee->store_result();

                $employee_exists = false;
                $existing_employee_id = null;

                if ($stmtCheckEmployee->num_rows > 0) {
                    $stmtCheckEmployee->bind_result($existing_employee_id);
                    $stmtCheckEmployee->fetch();
                    $employee_exists = true;
                    echo "Updating existing employee: $firstname $lastname $middlename \n";
                }
                $stmtCheckEmployee->free_result();

                // 🔹 GET OR CREATE POSITION
                $position_id = null;
                $stmtCheckPosition->bind_param("s", $position_name);
                $stmtCheckPosition->execute();
                $stmtCheckPosition->store_result();

                if ($stmtCheckPosition->num_rows > 0) {
                    $stmtCheckPosition->bind_result($position_id);
                    $stmtCheckPosition->fetch();
                } else {
                    $stmtInsertPosition->bind_param("s", $position_name);
                    $stmtInsertPosition->execute();
                    $position_id = $this->db->insert_id;
                }
                $stmtCheckPosition->free_result();

                if ($employee_exists) {
                    // 🔹 UPDATE EXISTING EMPLOYEE
                    $stmtUpdate->bind_param(
                        "sssssssssssssssssi",
                        $position_id,
                        $salary,
                        $basic_pay,
                        $rate_type,
                        $ot_rate,
                        $isAutoDeduct,
                        $weekly_payroll,
                        $clasification_id,
                        $sss_fund,
                        $allowance_rate,
                        $sss_no,
                        $ph_no,
                        $hdmf_no,
                        $bday,
                        $e_num,
                        $employee_code,
                        $ext,
                        $existing_employee_id
                    );
                    $stmtUpdate->execute();

                    if ($stmtUpdate->affected_rows >= 0) {
                        $updateCount++;
                        $employee_id = $existing_employee_id;

                        // Update contributions from the sheet. UPDATE alone would
                        // silently drop the amounts for an employee who has no
                        // contribution rows yet, so insert when none exists.
                        $contributions = [
                            ['id' => 1, 'amount' => $sss],
                            ['id' => 2, 'amount' => $phic],
                            ['id' => 3, 'amount' => $hdmf]
                        ];

                        foreach ($contributions as $contribution) {
                            $exists = $this->db->query("SELECT id FROM employee_contributions
                                WHERE employee_id = $employee_id AND contribution_id = " . (int) $contribution['id'] . " LIMIT 1");
                            if ($exists && $exists->num_rows > 0) {
                                $stmtUpdateContrib->bind_param("sss", $contribution['amount'], $employee_id, $contribution['id']);
                                $stmtUpdateContrib->execute();
                            } else {
                                $ptype = 1;
                                $insC = $this->db->prepare("INSERT INTO employee_contributions
                                    (employee_id, contribution_id, amount, payroll_type) VALUES (?, ?, ?, ?)");
                                $insC->bind_param("ssss", $employee_id, $contribution['id'], $contribution['amount'], $ptype);
                                $insC->execute();
                            }
                        }
                    }
                } else {
                    // 🔹 INSERT NEW EMPLOYEE
                    $stmtInsert->bind_param(
                        "ssssssssssssssssssssss",
                        $e_num,
                        $employee_code,
                        $firstname,
                        $middlename,
                        $lastname,
                        $position_id,
                        $salary,
                        $basic_pay,
                        $rate_type,
                        $status,
                        $ot_rate,
                        $isAutoDeduct,
                        $weekly_payroll,
                        $clasification_id,
                        $sss_fund,
                        $allowance_rate,
                        $sss_no,
                        $ph_no,
                        $hdmf_no,
                        $tin_no,
                        $ext,
                        $bday
                    );
                    $stmtInsert->execute();

                    if ($stmtInsert->affected_rows > 0) {
                        $insertCount++;
                        $employee_id = $this->db->insert_id;

                        // Insert contributions
                        $contributions = [
                            ['id' => 1, 'amount' => $sss],
                            ['id' => 2, 'amount' => $phic],
                            ['id' => 3, 'amount' => $hdmf]
                        ];

                        $query = "INSERT INTO employee_contributions (employee_id, contribution_id, amount, payroll_type) VALUES (?, ?, ?, ?)";
                        $stmt = $this->db->prepare($query);

                        foreach ($contributions as $contribution) {
                            $payroll_type = 1;
                            $stmt->bind_param("ssss", $employee_id, $contribution['id'], $contribution['amount'], $payroll_type);
                            $stmt->execute();
                        }

                        // Imported employees get a portal login on the default
                        // password too — the sheet carries no email, so the
                        // username is generated from their name.
                        $this->ensure_portal_account($employee_id, $firstname, $lastname);

                        // Insert loans and deductions for new employees only
                        if ($sss_loan > 0) {
                            $loan_status = 0;
                            $current_date = date('Y-m-d');
                            $data = " employee_id=$employee_id ";
                            $data .= ", loan_date='$current_date' ";
                            $data .= ", loan_amount = $sss_loan ";
                            $data .= ", loan_status = $loan_status ";
                            $data .= ", loan_type = 1 ";
                            $data .= ", loan_balance = $sss_loan ";
                            $data .= ", damount = $sss_loan ";
                            $this->db->query("INSERT INTO loans SET " . $data);
                        }

                        if ($hdmf_loan > 0) {
                            $loan_status = 0;
                            $current_date = date('Y-m-d');
                            $data = " employee_id=$employee_id ";
                            $data .= ", loan_date='$current_date' ";
                            $data .= ", loan_amount = $hdmf_loan ";
                            $data .= ", loan_status = $loan_status ";
                            $data .= ", loan_type = 2 ";
                            $data .= ", loan_balance = $hdmf_loan ";
                            $data .= ", damount = $hdmf_loan ";
                            $this->db->query("INSERT INTO loans SET " . $data);
                        }

                        if ($cash_bond > 0) {
                            $data = " employee_id='$employee_id' ";
                            $data .= ", deduction_id = 1 ";
                            $data .= ", amount = $cash_bond ";
                            $this->db->query("INSERT INTO employee_deductions SET " . $data);
                        }

                        if ($ppe > 0) {
                            $data = " employee_id='$employee_id' ";
                            $data .= ", deduction_id = 2 ";
                            $data .= ", amount = $ppe ";
                            $this->db->query("INSERT INTO employee_deductions SET " . $data);
                        }

                        if ($penalty > 0) {
                            $data = " employee_id='$employee_id' ";
                            $data .= ", deduction_id = 3 ";
                            $data .= ", amount = $penalty ";
                            $this->db->query("INSERT INTO employee_deductions SET " . $data);
                        }

                        if ($cash_advance > 0) {
                            $data = " employee_id='$employee_id' ";
                            $data .= ", deduction_id = 4 ";
                            $data .= ", amount = $cash_advance ";
                            $this->db->query("INSERT INTO employee_deductions SET " . $data);
                        }
                    } else {
                        throw new Exception("Failed to insert employee: " . $stmtInsert->error);
                    }
                }

                // ── Appended template columns, shared by insert and update ──
                if (!empty($employee_id)) {
                    // Shift / schedule (col R) -> employee_schedules, matched by name.
                    // A blank cell falls back to the global default shift
                    // (DTR_DEFAULT_SHIFT, db_connect.php) so every imported
                    // employee ends up with a working schedule.
                    $shift_name = trim((string) ($row[17] ?? ''));
                    $is_default = ($shift_name === '');
                    if ($is_default) $shift_name = DTR_DEFAULT_SHIFT;
                    $stmtCheckSchedule->bind_param("s", $shift_name);
                    $stmtCheckSchedule->execute();
                    $stmtCheckSchedule->store_result();
                    if ($stmtCheckSchedule->num_rows > 0) {
                        $stmtCheckSchedule->bind_result($schedule_id);
                        $stmtCheckSchedule->fetch();
                        $stmtCheckSchedule->free_result();
                        // Re-importing must not stack duplicate assignments; the
                        // default never overrides an existing assignment at all.
                        $dupWhere = $is_default ? '' : ' AND schedule_id = ' . (int) $schedule_id;
                        $chk = $this->db->query("SELECT id FROM employee_schedules
                            WHERE employee_id = $employee_id$dupWhere LIMIT 1");
                        if (!$chk || $chk->num_rows === 0) {
                            $eff  = date('Y-m-d');
                            $note = $is_default ? 'Default shift (auto-assigned)' : 'Imported';
                            $rd   = DTR_DEFAULT_REST_DAYS;
                            $ins = $this->db->prepare("INSERT INTO employee_schedules
                                (employee_id, schedule_id, effective_from, rest_days, notes) VALUES (?, ?, ?, ?, ?)");
                            $ins->bind_param("iisss", $employee_id, $schedule_id, $eff, $rd, $note);
                            $ins->execute();
                        }
                    } else {
                        $stmtCheckSchedule->free_result();
                    }

                    // Recurring deduction (cols S/T) -> employee_deductions, matched by name.
                    $ded_name   = trim((string) ($row[18] ?? ''));
                    $ded_amount = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[19] ?? '')));
                    if ($ded_name !== '' && $ded_amount > 0) {
                        $stmtCheckDeduct->bind_param("s", $ded_name);
                        $stmtCheckDeduct->execute();
                        $stmtCheckDeduct->store_result();
                        if ($stmtCheckDeduct->num_rows > 0) {
                            $stmtCheckDeduct->bind_result($deduction_id);
                            $stmtCheckDeduct->fetch();
                            $stmtCheckDeduct->free_result();
                            // Update in place when already assigned, so a re-import
                            // corrects the amount instead of adding a second row.
                            $chk = $this->db->query("SELECT id FROM employee_deductions
                                WHERE employee_id = $employee_id AND deduction_id = " . (int) $deduction_id . " LIMIT 1");
                            if ($chk && $chk->num_rows > 0) {
                                $ex = $chk->fetch_assoc();
                                $up = $this->db->prepare("UPDATE employee_deductions SET amount = ? WHERE id = ?");
                                $up->bind_param("di", $ded_amount, $ex['id']);
                                $up->execute();
                            } else {
                                $ins = $this->db->prepare("INSERT INTO employee_deductions
                                    (employee_id, deduction_id, amount) VALUES (?, ?, ?)");
                                $ins->bind_param("iid", $employee_id, $deduction_id, $ded_amount);
                                $ins->execute();
                            }
                        } else {
                            $stmtCheckDeduct->free_result();
                        }
                    }
                }
            }

            $this->db->commit();
            echo "Import completed: $insertCount inserted, $updateCount updated";
        } catch (Exception $e) {
            $this->db->rollback();
            echo "Error: " . $e->getMessage();
        }

        $stmtInsert->close();
        $stmtUpdate->close();
        $stmtCheckPosition->close();
        $stmtInsertPosition->close();
        $stmtUpdateContrib->close();
        $stmtCheckClas->close();
        $stmtCheckSchedule->close();
        $stmtCheckDeduct->close();
    }

    // Dry-run twin of import_employee(): parses the uploaded sheet with the
    // exact same column rules but performs NO writes, so the admin can review
    // what each row will do (insert / update / skip + warnings) before
    // committing. Any parsing change made to import_employee() must be
    // mirrored here, or the preview will lie.
    function preview_import_employee()
    {
        $allowedExt = ['xls', 'xlsx', 'csv'];
        $fileName = $_FILES['excelFile']['name'] ?? '';
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExt)) {
            return ['result' => false, 'message' => 'Invalid file type. Only .xlsx, .xls and .csv files are allowed.'];
        }

        try {
            $spreadsheet = IOFactory::load($_FILES['excelFile']['tmp_name']);
        } catch (Exception $e) {
            return ['result' => false, 'message' => 'Could not read the file: ' . $e->getMessage()];
        }
        $data = $spreadsheet->getActiveSheet()->toArray();
        if (count($data) > 1) {
            array_shift($data); // header row
        } else {
            return ['result' => false, 'message' => 'The sheet has no data rows below the header.'];
        }

        $stmtCheckEmployee = $this->db->prepare("SELECT id FROM employee
            WHERE LOWER(firstname) = LOWER(?) AND LOWER(lastname) = LOWER(?) AND LOWER(middlename) = LOWER(?)");
        $stmtCheckPosition = $this->db->prepare("SELECT id FROM position WHERE LOWER(name) = LOWER(?)");
        $stmtCheckClas     = $this->db->prepare("SELECT id FROM clasification WHERE LOWER(clasification) = LOWER(?)");
        $stmtCheckSchedule = $this->db->prepare("SELECT id FROM work_schedules WHERE LOWER(description) = LOWER(?) AND status = 1");
        $stmtCheckDeduct   = $this->db->prepare("SELECT id FROM deductions WHERE LOWER(deduction) = LOWER(?)");

        $rows = [];
        $counts = ['insert' => 0, 'update' => 0, 'skip' => 0, 'warning' => 0];
        $seen_names = [];   // duplicate-in-sheet detection
        $row_no = 1;        // header was row 1; data starts at 2

        foreach ($data as $row) {
            $row_no++;
            // Entirely blank line (trailing rows Excel keeps around) — ignore silently.
            $joined = trim(implode('', array_map(fn($c) => trim((string) $c), $row)));
            if ($joined === '') continue;

            $issues = [];

            // Name: "LASTNAME, FIRSTNAME[ MIDDLENAME]" in col D, legacy split cols B/C otherwise.
            $raw_name = trim((string) ($row[3] ?? ''));
            if (strpos($raw_name, ',') !== false) {
                [$last_part, $rest] = explode(',', $raw_name, 2);
                $lastname   = trim($last_part);
                $name_parts = preg_split('/\s+/', trim($rest), 2);
                $firstname  = $name_parts[0] ?? '';
                $middlename = $name_parts[1] ?? '';
            } else {
                $lastname   = $raw_name;
                $firstname  = trim((string) ($row[1] ?? ''));
                $middlename = trim((string) ($row[2] ?? ''));
            }

            $action = 'insert';
            if (empty($firstname) || empty($lastname)) {
                $action = 'skip';
                $issues[] = 'Name is missing or not in "LASTNAME, FIRSTNAME" format — row will be skipped.';
            } else {
                $stmtCheckEmployee->bind_param("sss", $firstname, $lastname, $middlename);
                $stmtCheckEmployee->execute();
                $stmtCheckEmployee->store_result();
                if ($stmtCheckEmployee->num_rows > 0) $action = 'update';
                $stmtCheckEmployee->free_result();

                $name_key = strtolower("$lastname|$firstname|$middlename");
                if (isset($seen_names[$name_key])) {
                    $issues[] = 'Duplicate of row ' . $seen_names[$name_key] . ' in this sheet — the later row overwrites the earlier one.';
                } else {
                    $seen_names[$name_key] = $row_no;
                }
            }

            // Position (col E): unknown names are auto-created by the import.
            $position_name = trim((string) ($row[4] ?? ''));
            $position_new = false;
            if ($action !== 'skip') {
                if ($position_name === '') {
                    $position_new = true;
                    $issues[] = 'Position is blank — an empty position record will be created.';
                } else {
                    $stmtCheckPosition->bind_param("s", $position_name);
                    $stmtCheckPosition->execute();
                    $stmtCheckPosition->store_result();
                    $position_new = ($stmtCheckPosition->num_rows === 0);
                    $stmtCheckPosition->free_result();
                }
            }

            // Classification (col N): unknown/blank falls back to Regular.
            $clas_name = trim((string) ($row[13] ?? ''));
            $clas_label = 'Regular (default)';
            if ($clas_name !== '') {
                $stmtCheckClas->bind_param("s", $clas_name);
                $stmtCheckClas->execute();
                $stmtCheckClas->store_result();
                if ($stmtCheckClas->num_rows > 0) {
                    $clas_label = $clas_name;
                } else {
                    $issues[] = 'Classification "' . $clas_name . '" not found — Regular will be used.';
                }
                $stmtCheckClas->free_result();
            }

            $basic_pay      = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[10] ?? '')));
            $allowance_rate = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[11] ?? '')));
            $ot_rate        = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[12] ?? '')));
            $salary         = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[20] ?? '')));
            $rate_type_raw  = strtolower(trim((string) ($row[21] ?? '')));
            $rate_type      = in_array($rate_type_raw, ['daily', 'monthly', 'fixed'], true) ? $rate_type_raw : 'daily';
            if ($rate_type_raw !== '' && $rate_type_raw !== $rate_type) {
                $issues[] = 'Rate type "' . $rate_type_raw . '" is not daily/monthly/fixed — "daily" will be used.';
            }
            if ($action !== 'skip' && $salary <= 0 && $basic_pay <= 0) {
                $issues[] = 'Both Daily Rate and Basic Pay are blank/zero.';
            }

            $sss  = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[14] ?? '')));
            $phic = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[15] ?? '')));
            $hdmf = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[16] ?? '')));

            // Shift (col R): blank -> global default; a named-but-unknown shift is NOT assigned.
            $shift_name = trim((string) ($row[17] ?? ''));
            $shift_label = $shift_name === '' ? DTR_DEFAULT_SHIFT . ' (default)' : $shift_name;
            if ($action !== 'skip') {
                $lookup = $shift_name === '' ? DTR_DEFAULT_SHIFT : $shift_name;
                $stmtCheckSchedule->bind_param("s", $lookup);
                $stmtCheckSchedule->execute();
                $stmtCheckSchedule->store_result();
                if ($stmtCheckSchedule->num_rows === 0) {
                    $issues[] = 'Shift "' . $lookup . '" not found among active schedules — no shift will be assigned.';
                    $shift_label = $shift_name === '' ? $shift_label : $shift_name;
                }
                $stmtCheckSchedule->free_result();
            }

            // Recurring deduction (cols S/T): needs a known name AND amount > 0.
            $ded_name   = trim((string) ($row[18] ?? ''));
            $ded_amount = floatval(preg_replace('/[^0-9.]/', '', (string) ($row[19] ?? '')));
            $ded_label = '';
            if ($ded_name !== '' && $action !== 'skip') {
                if ($ded_amount <= 0) {
                    $issues[] = 'Deduction "' . $ded_name . '" has no amount — it will not be assigned.';
                } else {
                    $stmtCheckDeduct->bind_param("s", $ded_name);
                    $stmtCheckDeduct->execute();
                    $stmtCheckDeduct->store_result();
                    if ($stmtCheckDeduct->num_rows > 0) {
                        $ded_label = $ded_name;
                    } else {
                        $issues[] = 'Deduction "' . $ded_name . '" not found — it will not be assigned.';
                    }
                    $stmtCheckDeduct->free_result();
                }
            }

            $counts[$action === 'skip' ? 'skip' : $action]++;
            if ($issues) $counts['warning']++;

            // Cap the payload; counts above stay exact for the whole sheet.
            if (count($rows) < 500) {
                $rows[] = [
                    'row_no'       => $row_no,
                    'action'       => $action,
                    'lastname'     => $lastname,
                    'firstname'    => $firstname,
                    'middlename'   => $middlename,
                    'position'     => $position_name,
                    'position_new' => $position_new,
                    'clas'         => $clas_label,
                    'daily_rate'   => $salary,
                    'basic_pay'    => $basic_pay,
                    'rate_type'    => $rate_type,
                    'ot_rate'      => $ot_rate,
                    'allowance'    => $allowance_rate,
                    'sss'          => $sss,
                    'phic'         => $phic,
                    'hdmf'         => $hdmf,
                    'sss_no'       => trim((string) ($row[7] ?? '')),
                    'ph_no'        => trim((string) ($row[5] ?? '')),
                    'hdmf_no'      => trim((string) ($row[9] ?? '')),
                    'shift'        => $shift_label,
                    'deduction'    => $ded_label,
                    'ded_amount'   => $ded_amount,
                    'issues'       => $issues,
                ];
            }
        }

        $stmtCheckEmployee->close();
        $stmtCheckPosition->close();
        $stmtCheckClas->close();
        $stmtCheckSchedule->close();
        $stmtCheckDeduct->close();

        $total = $counts['insert'] + $counts['update'] + $counts['skip'];
        return [
            'result'    => true,
            'file'      => $fileName,
            'total'     => $total,
            'truncated' => $total > count($rows),
            'counts'    => $counts,
            'rows'      => $rows,
        ];
    }
}
