<?php
ini_set('serialize_precision', '-1');
session_start();

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

    public function __construct()
    {
        ob_start();

        include 'db_connect.php';

        $this->db = $conn;
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
            return ['result' => false, 'message' => 'Please enter your username and password.'];
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
            if ((int)$row['role'] !== 5 && !empty($row['password']) && password_verify($password, $row['password'])) {
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
            4 => 'index.php?page=payroll',     // Payroll Clerk
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
     * Give a newly created employee a portal login on the default password.
     *
     * Username is the supplied email when it is a real one, otherwise
     * firstname.lastname@<default domain>. employee_portal_accounts.username is
     * UNIQUE, so a numeric suffix is added until the candidate is free —
     * duplicate emails across staff are common and must not abort the insert.
     *
     * No-op when the employee already has an account, which keeps re-imports
     * from resetting a password the employee has already changed.
     */
    private function ensure_portal_account($employee_id, $firstname, $lastname, $email = '')
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

        $hash = password_hash(PORTAL_DEFAULT_PASSWORD, PASSWORD_BCRYPT);
        $ins  = $this->db->prepare("INSERT INTO employee_portal_accounts
            (employee_id, username, password, is_active, must_change) VALUES (?, ?, ?, 1, 1)");
        $ins->bind_param('iss', $employee_id, $candidate, $hash);
        $ins->execute();

        return $candidate;
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

    function login2()
    {
        extract($_POST);

        $qry = $this->db->query("SELECT * FROM users where username = '" . $email . "' and password = '" . md5($password) . "' ");

        if ($qry->num_rows > 0) {
            foreach ($qry->fetch_array() as $key => $value) {
                if ($key != 'passwors' && !is_numeric($key)) {
                    $_SESSION['login_' . $key] = $value;
                }
            }

            return 1;
        } else {
            return 3;
        }
    }

    function logout()
    {
        session_destroy();

        foreach ($_SESSION as $key => $value) {
            unset($_SESSION[$key]);
        }

        header("location:login.php");
    }

    function logout2()
    {
        session_destroy();

        foreach ($_SESSION as $key => $value) {
            unset($_SESSION[$key]);
        }

        header("location:../index.php");
    }


    function signup()
    {
        extract($_POST);

        $data = " name = '$name' ";

        $data .= ", contact = '$contact' ";

        $data .= ", address = '$address' ";

        $data .= ", username = '$email' ";

        $data .= ", password = '" . md5($password) . "' ";

        $data .= ", type = 3";

        $chk = $this->db->query("SELECT * FROM users where username = '$email' ")->num_rows;

        if ($chk > 0) {
            return 2;

            exit();
        }

        $save = $this->db->query("INSERT INTO users set " . $data);

        if ($save) {
            $qry = $this->db->query("SELECT * FROM users where username = '" . $email . "' and password = '" . md5($password) . "' ");

            if ($qry->num_rows > 0) {
                foreach ($qry->fetch_array() as $key => $value) {
                    if ($key != 'passwors' && !is_numeric($key)) {
                        $_SESSION['login_' . $key] = $value;
                    }
                }
            }

            return 1;
        }
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


    function save_settings()
    {
        extract($_POST);
        $data = " name = '" . str_replace("'", "&#x2019;", $name) . "' ";
        $data .= ", email = '$email' ";
        $data .= ", contact = '$contact' ";
        $data .= ", about_content = '" . htmlentities(str_replace("'", "&#x2019;", $about)) . "' ";
        if ($_FILES['img']['tmp_name'] != '') {
            $fname = strtotime(date('y-m-d H:i')) . '_' . $_FILES['img']['name'];
            $move = move_uploaded_file($_FILES['img']['tmp_name'], 'assets/img/' . $fname);
            $data .= ", cover_img = '$fname' ";
        }

        $chk = $this->db->query("SELECT * FROM system_settings");
        if ($chk->num_rows > 0) {
            $save = $this->db->query("UPDATE system_settings set " . $data);
        } else {
            $save = $this->db->query("INSERT INTO system_settings set " . $data);
        }
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
                $query = "INSERT INTO employee
                (employee_no, employee_code, firstname, middlename, lastname, position_id, department_id, salary, basic_pay, rate_type, status, ot_rate, isAutoDeduct, weekly_payroll, clasification_id, sss_fund, allowance_rate, bday, ext, bank_id, bank_account_no)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param("sssssssssssssssssssss", $e_num, $employee_code, $firstname, $middlename, $lastname, $position_id, $department_id, $salary, $basic_pay, $rate_type, $status, $ot_rate, $isAutoDeduct, $weekly_payroll, $clasification_id, $sss_fund, $allowance_rate, $bday, $ext, $bank_id, $bank_account_no);
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

                // Every new employee gets a portal login on the default password
                // (must_change = 1) so they can sign in without an extra step.
                $this->ensure_portal_account($employee_id, $firstname, $lastname, $_POST['email'] ?? '');

                $this->db->commit();
                return $employee_id;
            } else {
                // Update existing employee
                $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
                $query = "UPDATE employee SET
                employee_code=COALESCE(NULLIF(?, ''), employee_code), firstname=?, middlename=?, lastname=?, position_id=?, department_id=?, salary=?, basic_pay=?, rate_type=?, status=?, ot_rate=?, isAutoDeduct=?, weekly_payroll=?, clasification_id=?, sss_fund=?, allowance_rate=?, bday=?, ext=?, bank_id=?, bank_account_no=?
                WHERE id=?";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param("sssssssssssssssssssss", $employee_code, $firstname, $middlename, $lastname, $position_id, $department_id, $salary, $basic_pay, $rate_type, $status, $ot_rate, $isAutoDeduct, $weekly_payroll, $clasification_id, $sss_fund, $allowance_rate, $bday, $ext, $bank_id, $bank_account_no, $id);
                $stmt->execute();

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
        $is_graveyard  = intval($_POST['is_graveyard'] ?? 0);
        $has_nsd       = intval($_POST['has_nsd'] ?? 0);
        $nsd_rate      = floatval($_POST['nsd_rate'] ?? 0);

        if (!$description || !$start_time || !$end_time) {
            return ['result' => false, 'message' => 'Description, start time and end time are required'];
        }

        if ($id) {
            $stmt = $this->db->prepare(
                "UPDATE work_schedules SET description=?, start_time=?, end_time=?, total_hours=?,
                 break_minutes=?, is_graveyard=?, has_nsd=?, nsd_rate=? WHERE id=?"
            );
            $stmt->bind_param(
                'sssdiiidi',
                $description,
                $start_time,
                $end_time,
                $total_hours,
                $break_minutes,
                $is_graveyard,
                $has_nsd,
                $nsd_rate,
                $id
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO work_schedules (description, start_time, end_time, total_hours,
                 break_minutes, is_graveyard, has_nsd, nsd_rate) VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'sssdiiid',
                $description,
                $start_time,
                $end_time,
                $total_hours,
                $break_minutes,
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
        $rest_days      = $this->normalizeRestDays($_POST['rest_days'] ?? '0');
        $changed_by     = $_SESSION['login_id'] ?? null;

        if (!$employee_id || !$schedule_id) {
            return ['result' => false, 'message' => 'employee_id and schedule_id are required'];
        }

        $this->db->begin_transaction();
        try {
            // Find the currently open schedule row (if any)
            $openStmt = $this->db->prepare(
                "SELECT id, effective_from FROM employee_schedules WHERE employee_id=? AND effective_to IS NULL LIMIT 1"
            );
            $openStmt->bind_param('i', $employee_id);
            $openStmt->execute();
            $open = $openStmt->get_result()->fetch_assoc();

            if ($open && $open['effective_from'] >= $effective_from) {
                $this->db->rollback();
                return ['result' => false, 'message' => 'Effective date must be after the current schedule\'s start date (' . date('M j, Y', strtotime($open['effective_from'])) . ').'];
            }

            // Close the currently active schedule (if any)
            if ($open) {
                $prev_date = date('Y-m-d', strtotime($effective_from . ' -1 day'));
                $stmt1 = $this->db->prepare(
                    "UPDATE employee_schedules SET effective_to=? WHERE id=?"
                );
                $stmt1->bind_param('si', $prev_date, $open['id']);
                $stmt1->execute();
            }

            // Insert new assignment
            $stmt2 = $this->db->prepare(
                "INSERT INTO employee_schedules (employee_id, schedule_id, effective_from, notes, rest_days, changed_by)
                 VALUES (?,?,?,?,?,?)"
            );
            $stmt2->bind_param('iisssi', $employee_id, $schedule_id, $effective_from, $notes, $rest_days, $changed_by);
            $stmt2->execute();

            $this->db->commit();
            return ['result' => true, 'message' => 'Schedule assigned'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }
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
        if (empty($periods)) return '';
        foreach ($periods as $p) {
            if ($p['effective_from'] <= $ymd && ($p['effective_to'] === null || $p['effective_to'] >= $ymd)) {
                return (string)$p['rest_days'];
            }
        }
        return '';
    }

    // True if $ymd (a Y-m-d date) falls on one of the employee's rest days per $periods.
    private function isRestDay($periods, $ymd)
    {
        $rd = $this->restDaysForDate($periods, $ymd);
        if ($rd === '') return false;
        $w = (int)date('w', strtotime($ymd)); // 0=Sun … 6=Sat
        return in_array($w, array_map('intval', explode(',', $rd)), true);
    }

    // Core shift-assignment for ONE employee. Closes the open period / opens a new one
    // (with same-day correction). Caller owns the transaction. Returns 'updated' | 'unchanged' | 'skipped'.
    private function applyScheduleChange($emp, $schedule_id, $effective_from, $notes, $changed_by, $rest_days = '0')
    {
        $emp = (int) $emp;
        $schedule_id = (int) $schedule_id;
        $rest_days = $this->normalizeRestDays($rest_days);

        $openStmt = $this->db->prepare(
            "SELECT id, schedule_id, effective_from, rest_days FROM employee_schedules
             WHERE employee_id=? AND effective_to IS NULL LIMIT 1"
        );
        $openStmt->bind_param('i', $emp);
        $openStmt->execute();
        $open = $openStmt->get_result()->fetch_assoc();

        if ($open) {
            if ((int)$open['schedule_id'] === $schedule_id && (string)$open['rest_days'] === $rest_days) return 'unchanged';

            if ($open['effective_from'] === $effective_from) {
                // Same-day correction: overwrite the open row instead of stacking periods
                $u = $this->db->prepare(
                    "UPDATE employee_schedules SET schedule_id=?, notes=?, rest_days=?, changed_by=? WHERE id=?"
                );
                $u->bind_param('issii', $schedule_id, $notes, $rest_days, $changed_by, $open['id']);
                $u->execute();
                return 'updated';
            }
            if ($effective_from > $open['effective_from']) {
                // Close current period the day before, then open the new one
                $prev_date = date('Y-m-d', strtotime($effective_from . ' -1 day'));
                $c = $this->db->prepare("UPDATE employee_schedules SET effective_to=? WHERE id=?");
                $c->bind_param('si', $prev_date, $open['id']);
                $c->execute();
                $ins = $this->db->prepare(
                    "INSERT INTO employee_schedules (employee_id, schedule_id, effective_from, notes, rest_days, changed_by)
                     VALUES (?,?,?,?,?,?)"
                );
                $ins->bind_param('iisssi', $emp, $schedule_id, $effective_from, $notes, $rest_days, $changed_by);
                $ins->execute();
                return 'updated';
            }
            // Effective date is before the current period's start — can't backdate over it
            return 'skipped';
        }

        // No schedule yet — just insert
        $ins = $this->db->prepare(
            "INSERT INTO employee_schedules (employee_id, schedule_id, effective_from, notes, rest_days, changed_by)
             VALUES (?,?,?,?,?,?)"
        );
        $ins->bind_param('iisssi', $emp, $schedule_id, $effective_from, $notes, $rest_days, $changed_by);
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
        // Default '0' (Sunday) keeps prior behavior if a caller omits rest_days.
        $rest_days      = $_POST['rest_days'] ?? '0';
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
            $sel = $this->db->prepare(
                "SELECT id, rest_days FROM employee_schedules WHERE employee_id=? AND effective_to IS NULL LIMIT 1"
            );
            $upd = $this->db->prepare(
                "UPDATE employee_schedules SET rest_days=?, changed_by=? WHERE id=?"
            );
            foreach ($ids as $emp) {
                $sel->bind_param('i', $emp);
                $sel->execute();
                $open = $sel->get_result()->fetch_assoc();
                if (!$open) { $skipped++; continue; }              // no active schedule to attach rest days to
                if ((string)$open['rest_days'] === $rest_days) { $unchanged++; continue; }
                $upd->bind_param('sii', $rest_days, $changed_by, $open['id']);
                $upd->execute();
                $updated++;
                $notify[] = $emp;
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
        $rest_days      = $this->normalizeRestDays($_POST['rest_days'] ?? '0');
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

                $ins = $this->db->prepare(
                    "INSERT INTO schedule_plan (employee_id, schedule_id, effective_from, notes, rest_days, status, created_by)
                     VALUES (?,?,?,?,?,0,?)"
                );
                $ins->bind_param('iisssi', $emp, $schedule_id, $effective_from, $notes, $rest_days, $created_by);
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
                $r = $this->applyScheduleChange($d['employee_id'], $d['schedule_id'], $d['effective_from'], $d['notes'], $changed_by, $d['rest_days'] ?? '0');
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

        if (!$employee_id || !in_array($request_type, ['incident', 'overtime'], true) || !$request_date || !$reason) {
            return ['result' => false, 'message' => 'Missing required fields'];
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
        $label = $request_type === 'incident' ? 'attendance incident report' : 'overtime request';
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

    function decide_attendance_request()
    {
        $id      = intval($_POST['id'] ?? 0);
        $status  = intval($_POST['status'] ?? 0); // 1 approve, 2 reject
        $remarks = trim($_POST['remarks'] ?? '');
        $uid     = $_SESSION['login_id'] ?? null;
        $role    = (int) ($_SESSION['login_role'] ?? 0);

        if (!in_array($role, [1, 8, 9], true)) {
            return ['result' => false, 'message' => 'Only Admin, Department Head or HR Head can decide.'];
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
            // instead of leaving it at 0 pending a manual edit by the timekeeper.
            $ot_applied = true;
            if ($status == 1 && $req['request_type'] === 'overtime' && $req['ot_hours_requested'] !== null) {
                $ot_applied = $this->applyOvertimeToDtr($req);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => $e->getMessage()];
        }

        // Notify the employee on their portal bell (recipient_type='employee').
        $label   = $req['request_type'] === 'incident' ? 'attendance incident report' : 'overtime request';
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
                 status=0, decision_note=NULL, decided_by=NULL, decided_at=NULL WHERE id=?"
            );
            $stmt->bind_param('sddddsdi', $logs, $work_hours, $overtime, $late, $undertime, $day_type, $nsd_hours, $existing_id);
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
                                      day_type, nsd_hours, is_complete, logs, attendance_type, status)
             VALUES (?,?,?,?,?,?,?,?,?,1,?,'incident',0)"
        );
        $stmt->bind_param('iisddddsds', $ddtr_id, $employee_id, $date, $work_hours, $overtime, $late, $undertime, $day_type, $nsd_hours, $logs);
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
            "SELECT id FROM DTR_details WHERE employee_id = $employee_id AND date_time = '$date' ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();

        if ($existing) {
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
        $stmt = $this->db->prepare(
            "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, overtime, logs, attendance_type, status)
             VALUES (?,?,?,0,?,'[]','overtime',0)"
        );
        $stmt->bind_param('iisd', $ddtr_id, $employee_id, $date, $ot_hours);
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

                $sql2 = "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, overtime) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt2 = $this->db->prepare($sql2);
                $stmt2->bind_param('sssssss', $ddtr_id, $employee_id, $date_time, $hours, $logs, $attendance_type, $overtime);
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
                $sql2 = "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, overtime, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt2 = $this->db->prepare($sql2);
                $stmt2->bind_param('ssssssss', $ddtr_id, $employee_id, $date_time, $hours, $logs, $attendance_type, $overtime, $notes);
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

        if ($recalculate) {
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

                // Preload each employee's rest-day schedule periods overlapping this payroll
                // period, so we can auto-count rest-day duty from the DTR (replacing the old
                // hardcoded "Sunday" assumption). Keyed by employee_id.
                $restMap = [];
                $rq = $this->db->prepare(
                    "SELECT employee_id, effective_from, effective_to, rest_days
                     FROM employee_schedules
                     WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                     ORDER BY effective_from DESC"
                );
                $rq->bind_param('ss', $date_to, $date_from);
                $rq->execute();
                $rres = $rq->get_result();
                while ($rrow = $rres->fetch_assoc()) {
                    $restMap[$rrow['employee_id']][] = $rrow;
                }

                // Preload approved PAID leave days per employee overlapping this period.
                // Paid leave (leave_types.is_paid = 1) is treated as paid-present: it does
                // NOT count as an absence (monthly) and IS paid (daily). Unpaid (LWOP) leave
                // needs no handling — with no DTR row it already falls into the absent tally.
                // Keyed [employee_id][Y-m-d] => day fraction (0.5 for the half-day date).
                $leaveMap = [];
                $lvq = $this->db->prepare(
                    "SELECT lr.employee_id, lr.dates, lr.date_from, lr.date_to, lr.is_half_day, lr.half_date
                     FROM leave_requests lr
                     INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                     WHERE lr.status = 1 AND lt.is_paid = 1 AND lr.date_from <= ? AND lr.date_to >= ?"
                );
                $lvq->bind_param('ss', $date_to, $date_from);
                $lvq->execute();
                $lvres = $lvq->get_result();
                while ($lv = $lvres->fetch_assoc()) {
                    $eid  = $lv['employee_id'];
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
                        if (!isset($leaveMap[$eid][$ymd]) || $leaveMap[$eid][$ymd] < $frac) {
                            $leaveMap[$eid][$ymd] = $frac;   // overlapping leaves → keep larger fraction
                        }
                    }
                }

                // Preload declared-holiday dates (legal + special) in this period.
                // A holiday is a paid non-working day, so a MONTHLY employee who
                // didn't work it must NOT be counted absent for it. Keyed Y-m-d.
                $holidayDates = [];
                $hq = $this->db->prepare(
                    "SELECT start_date, end_date FROM calendar_events
                     WHERE type IN (1, 3) AND start_date <= ? AND COALESCE(end_date, start_date) >= ?"
                );
                $hq->bind_param('ss', $date_to, $date_from);
                $hq->execute();
                $hres = $hq->get_result();
                while ($h = $hres->fetch_assoc()) {
                    $hEnd = $h['end_date'] ?: $h['start_date'];
                    for ($d = strtotime($h['start_date']); $d <= strtotime($hEnd); $d = strtotime('+1 day', $d)) {
                        $holidayDates[date('Y-m-d', $d)] = true;
                    }
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

                    // Cap hours at 8 (1 day)
                    // Cap hours at 8 (1 day)
                    $work_hours = floor($row["work_hours"]) >= 8 ? 8 : $row["work_hours"];

                    // Convert to days using your special rules
                    if ($work_hours == 8) {
                        $days = 1;
                    } else if ($work_hours == 4.5625) {
                        $days = 0.5625;
                    } else {
                        $days = $work_hours / 8;
                    }



                    // Convert to days using your special rules

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
                        ];
                        $ipresent++;
                    }

                    // Rest-day duty: if this DTR day is one of the employee's rest days
                    // (effective on that date), the worked fraction counts toward the
                    // rest-day premium instead of being assumed to be Sunday.
                    $ymd = date('Y-m-d', strtotime($row['date_time']));
                    if ($this->isRestDay($restMap[$employee_id] ?? [], $ymd)) {
                        $grouped_data[$employee_id]["rest_duty"] += $days;
                    }

                    $under_time = 0; // 8 - $work_hours
                    $grouped_data[$employee_id]["under_time"] += $under_time;

                    $per_day = $row['salary'];
                    $basic_pay = $row['basic_pay'];
                    $per_hour = $per_day / 8;
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
                    $grouped_data[$employee_id]["overtime"] +=  $row['overtime'];
                    $grouped_data[$employee_id]["late_in_minutes"]  += $row['late'];
                    $grouped_data[$employee_id]["undertime"]  +=  $row['undertime'];
                    $grouped_data[$employee_id]["isAutoDeduct"]  =  $isAutoDeduct;
                    $grouped_data[$employee_id]["site_id"]  = $site_id;
                    $grouped_data[$employee_id]["sss_fund"]  = $sss_fund;
                    $grouped_data[$employee_id]["allowance_amount"]  = $allowance_rate;
                    $grouped_data[$employee_id]["rate_type"]  = in_array($row['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $row['rate_type'] : 'daily';
                    $grouped_data[$employee_id]["date_time"]  = $row['date_time'];
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
                            $work_hours2 = floor($row2["work_hours"]) >= 8 ? 8 : $row2["work_hours"];
                            $data__details[] = [
                                "site_id" => $row2["site_id"],
                                "date_time" => $row2["date_time"],
                                "work_hours" => $work_hours2,
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
                                $data['overtime'] += $data__detail['overtime'];
                                $data['undertime'] += $data__detail['undertime'];
                                $data['late_in_minutes'] += $data__detail['late'];
                                $data['present'] += $data__detail['work_hours'] / 8;
                                // Count rest-day duty from cross-cluster attendance too.
                                $d2ymd = date('Y-m-d', strtotime($data__detail['date_time']));
                                if ($this->isRestDay($restMap[$employee_id] ?? [], $d2ymd)) {
                                    $data['rest_duty'] = ($data['rest_duty'] ?? 0) + $data__detail['work_hours'] / 8;
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
                                //check if auto deduct and sss
                                // if ($row['contribution_id'] === 1 &&  $data['isAutoDeduct']) {
                                //     if ($weekly_payroll === 1) {
                                //         $sss_amount = $this->getSSSWeeklyDeduction($data['basic_pay']);
                                //     } else {
                                //         $sss_amount = $this->getSSSMonthlyDeduction($data['basic_pay']);
                                //     }
                                //     $contribute_amount += $sss_amount;
                                //     $contributions[] = ["amount" => $sss_amount, "contribution_id" => 1];
                                // } else {
                                //     $contribute_amount += $row['amount'];
                                //     $contributions[] = ["amount" => $row['amount'], "contribution_id" => $row['contribution_id']];
                                // }
                                $contribute_amount += $row['amount'];
                                $contributions[] = ["amount" => $row['amount'], "contribution_id" => (int)  $row['contribution_id']];
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
                                // Skip loans not yet started (before loan_date) or fully paid.
                                if (!empty($row['loan_date']) && date('Y-m-d', strtotime($row['loan_date'])) > $date_to) {
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
                    // Pay basis for this employee, frozen onto the payroll item so a later
                    // rate-type change doesn't retro-alter this run.
                    $rate_type = in_array($data['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $data['rate_type'] : 'daily';
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
                            if (isset($leaveMap[$employee_id][$ymd]) && !$this->isRestDay($restMap[$employee_id] ?? [], $ymd)) {
                                $paid_leave += (float) $leaveMap[$employee_id][$ymd];
                            }
                        }
                    }
                    if ($rate_type === 'monthly') {
                        $expected_days = 0;
                        for ($d = strtotime($date_from); $d <= strtotime($date_to); $d = strtotime('+1 day', $d)) {
                            $eymd = date('Y-m-d', $d);
                            // Expected work days exclude rest days AND declared holidays
                            // (a holiday is paid non-working — never an absence).
                            if (!$this->isRestDay($restMap[$employee_id] ?? [], $eymd) && empty($holidayDates[$eymd])) {
                                $expected_days++;
                            }
                        }
                        // Paid leave is not an absence.
                        $absent = max(0, (int) round($expected_days - $present - $paid_leave));
                    }

                    $sql2 = "INSERT INTO payroll_items
                    (payroll_id, employee_id, salary, allowance_amount, contribute_amount,
                     deduction_amount, deductions, contributions, total_hours,
                     per_day, under_time, late, present, ot_rate, per_minute, ot, site_id, loans,basic_pay,sss_fund,refunds,sunday_duty,absent,paid_leave,rate_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $stmt2 = $this->db->prepare($sql2);
                    if (!$stmt2) {
                        throw new Exception('Failed to prepare statement: ' . $this->db->error);
                    }

                    $stmt2->bind_param(
                        'sssssssssssssssssssssssss',
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
                        $rate_type
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
                        if (!empty($row['loan_date']) && date('Y-m-d', strtotime($row['loan_date'])) > $date_to) continue;
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

            $ins = $this->db->prepare("INSERT INTO payroll_items
                (payroll_id, employee_id, salary, allowance_amount, contribute_amount,
                 deduction_amount, deductions, contributions, total_hours,
                 per_day, under_time, late, present, ot_rate, per_minute, ot, site_id, loans, basic_pay, sss_fund, refunds, sunday_duty, absent, rate_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$ins) continue;
            $ins->bind_param(
                'ssssssssssssssssssssssss',
                $payroll_id, $employee_id, $salary, $allowance_amount, $contribute_amount,
                $deduction_amount, $deductions_j, $contributions_j, $zero,
                $per_day, $zero, $zero, $zero, $ot_rate, $per_minute, $zero, $first_site, $loans_j, $basic_pay, $sss_fund, $refunds_j, $zero, $zero, $rate_type
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
        if ((int)$rv['status'] !== 2) return ['result' => false, 'message' => 'Only disputes can be resolved.'];

        // reviewed_at = reviewed_at guards against schemas where the column still
        // has ON UPDATE CURRENT_TIMESTAMP: resolving must not overwrite when the
        // employee actually signed off.
        $stmt = $this->db->prepare("UPDATE $table SET resolved_at = NOW(), resolved_by = ?, admin_reply = ?, reviewed_at = reviewed_at WHERE id = ?");
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
            'Your dispute was addressed',
            "HR responded to your $what dispute for $period: $reply",
            'ri-chat-check-line',
            'info',
            $link
        );

        return ['result' => true, 'message' => 'Dispute resolved and the employee has been notified.'];
    }

    // Bulk-send several DTR batches for review (status 1 → 3). ids = array of DTR ids.
    function bulk_send_dtr_for_review()
    {
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
        return $this->_remindReview('dtr');
    }
    function remind_payroll_review()
    {
        return $this->_remindReview('payroll');
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
            $i = 1;
            while ($i == 1) {
                $ref_no = date('Y') . '-' . mt_rand(1, 9999);

                $chk = $this->db->query("SELECT * FROM payroll where ref_no = '$ref_no' ")->num_rows;

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
                $sql2 = "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, overtime, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt2 = $this->db->prepare($sql2);
                $stmt2->bind_param('ssssssss', $id, $employee_id, $date_time, $hours, $logs, $attendance_type, $overtime, $notes);
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

        $site_id = intval($_POST['site_id'] ?? 0);

        if (!$employee_id || !$scan_time || !$site_id) {
            return ['result' => false, 'message' => 'Missing employee_id, scan_time or site_id'];
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

        // Resolve employee's current schedule
        $stmt_sched = $this->db->prepare("
            SELECT ws.* FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = ? AND es.effective_from <= ? AND (es.effective_to IS NULL OR es.effective_to >= ?)
            ORDER BY es.effective_from DESC LIMIT 1
        ");
        $stmt_sched->bind_param('iss', $employee_id, $scan_date, $scan_date);
        $stmt_sched->execute();
        $schedule = $stmt_sched->get_result()->fetch_assoc();

        // Overnight shift = graveyard flag OR any schedule whose end time wraps past
        // midnight (e.g. Evening 3PM–12AM, Night 11PM–8AM), even when the flag
        // wasn't ticked on the schedule.
        $is_overnight = $schedule && ($schedule['is_graveyard'] || $schedule['end_time'] <= $schedule['start_time']);

        // For overnight shifts a scan may belong to the shift that STARTED the
        // previous day. Three cases:
        //  1. Yesterday has an OPEN record and the gap since its last punch still
        //     looks like a single shift (≤16h) → this scan is its time-out. The gap
        //     guard is what keeps a stray punch from an unrelated time of day
        //     (17h+ ago) from merging, while an early clock-in (e.g. 7:45 PM for an
        //     11 PM shift) and a long-OT clock-out both still pair.
        //  2. Yesterday's record is already COMPLETE but this scan is within the
        //     duplicate window of its closing punch → re-date it so the debounce
        //     below swallows the double-tap instead of opening a fresh day.
        //  3. No record yesterday at all, but the scan lands after midnight and
        //     BEFORE yesterday's scheduled end → a LATE time-in; anchor it to
        //     yesterday's shift so late/undertime compute against the right day
        //     instead of opening (and mangling) today.
        if ($is_overnight) {
            $prev_date = date('Y-m-d', strtotime('-1 day', strtotime($scan_date)));
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
                if (!$prevRec['is_complete']) {
                    if ($gap > 0 && $gap <= 16 * 3600) $scan_date = $prev_date;   // case 1: time-out
                } elseif ($gap >= 0 && $gap < 300) {
                    $scan_date = $prev_date;                                      // case 2: double-tap
                }
            } else {
                $prev_end = strtotime($prev_date . ' ' . $schedule['end_time']);
                if ($schedule['end_time'] <= $schedule['start_time']) {
                    $prev_end = strtotime('+1 day', $prev_end); // wraps → ends today
                }
                if ($scan_ts < $prev_end) $scan_date = $prev_date;                // case 3: late time-in
            }

            // The re-dated day may fall under a different schedule assignment —
            // resolve it again so hours compute against the shift actually worked.
            if ($scan_date === $prev_date) {
                $stmt_sched->bind_param('iss', $employee_id, $scan_date, $scan_date);
                $stmt_sched->execute();
                $resched = $stmt_sched->get_result()->fetch_assoc();
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

            if (!$detail) {
                // First scan — time-in only, mark incomplete
                $logs = json_encode([$log_entry]);
                $stmt6 = $this->db->prepare(
                    "INSERT INTO DTR_details (ddtr_id, employee_id, date_time, work_hours, logs, attendance_type, day_type, nsd_hours, is_complete, notes)
                     VALUES (?, ?, ?, 0, ?, 'biometric', ?, 0, 0, ?)"
                );
                $stmt6->bind_param('iissss', $ddtr_id, $employee_id, $scan_date, $logs, $day_type, $shift_note);
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
                $earliest   = min($timestamps);
                $latest     = max($timestamps);

                $raw_hours  = ($latest - $earliest) / 3600;
                $break_hrs  = ($schedule['break_minutes'] ?? 60) / 60;
                $work_hours = max(0, $raw_hours - $break_hrs);

                // Schedule-based: late, undertime, overtime.
                // Anchor the shift to the DTR row's day ($scan_date), NOT the first
                // punch's calendar date — on an overnight shift a late time-in lands
                // after midnight, and anchoring to it would shift the whole schedule
                // one day forward (zero late, ~22h undertime).
                $late = $undertime = $overtime = 0;
                if ($schedule) {
                    $sched_start = strtotime($scan_date . ' ' . $schedule['start_time']);
                    $sched_end   = strtotime($scan_date . ' ' . $schedule['end_time']);
                    if ($is_overnight) $sched_end = strtotime('+1 day', $sched_end);
                    $late      = round(max(0, ($earliest - $sched_start) / 3600), 2);
                    $undertime = round(max(0, ($sched_end - $latest) / 3600), 2);
                    $overtime  = round(max(0, ($latest - $sched_end) / 3600), 2);
                    $work_hours = round(min($work_hours, $schedule['total_hours']), 2);
                } else {
                    $overtime   = round(max(0, $work_hours - 8), 2);
                    $work_hours = round(min(8, $work_hours), 2);
                }

                // Proper NSD: count hours between 10PM–6AM only
                $nsd_hours = 0;
                $date_str  = date('Y-m-d', $earliest);
                $nsd_windows = [
                    [strtotime($date_str . ' 22:00:00'), strtotime($date_str . ' 23:59:59')],
                    [strtotime($date_str . ' 00:00:00'), strtotime($date_str . ' 06:00:00')],
                    [
                        strtotime(date('Y-m-d', strtotime('+1 day', $earliest)) . ' 00:00:00'),
                        strtotime(date('Y-m-d', strtotime('+1 day', $earliest)) . ' 06:00:00')
                    ],
                ];
                foreach ($nsd_windows as $w) {
                    $overlap = max(0, min($latest, $w[1]) - max($earliest, $w[0]));
                    $nsd_hours += $overlap / 3600;
                }
                $nsd_hours = round($nsd_hours, 2);

                $logs = json_encode($existing_logs);
                $stmt7 = $this->db->prepare(
                    "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, late=?, undertime=?,
                     day_type=?, nsd_hours=?, is_complete=1 WHERE id=?"
                );
                $stmt7->bind_param(
                    'sddddsdi',
                    $logs,
                    $work_hours,
                    $overtime,
                    $late,
                    $undertime,
                    $day_type,
                    $nsd_hours,
                    $detail['id']
                );
                $stmt7->execute();
                $this->db->commit();

                // Notify the employee: day complete, with the computed hours summary.
                // Best-effort: a notification hiccup must never fail an already-saved punch.
                try {
                    $ot_txt   = $overtime  > 0 ? ', ' . round($overtime, 2) . ' hr OT'         : '';
                    $late_txt = $late      > 0 ? ', ' . (int) round($late * 60) . ' min late'  : '';
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
                    'late_minutes'      => (int) round($late * 60),
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

        // Only administrators may operate the scanner desktop app.
        if ((int) $user['role'] !== 1) {
            return ['result' => false, 'message' => 'Access denied — administrator account required'];
        }

        // Scanner posts attendance against a site; fall back to the first active
        // site when the account has none assigned.
        $site_id = (int) ($user['site_id'] ?? 0);
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

    function update_payroll_item()
    {
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
            return ['result' => true, 'message' => 'save'];
        } catch (mysqli_sql_exception $e) {
            return ['result' => false, 'message' => $e->getMessage()];
        }
        return ['result' => false, 'message' => 'save'];
    }

    function update_payroll_item_new()
    {
        $items = $_POST['items'];

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
                    (
                        ((pi.basic_pay + (pi.allowance_amount*pi.allowance_days) - (pi.absent*pi.per_day))/2)
                        + (pi.ot * pi.ot_rate)
                        + (pi.legal_holiday * pi.per_day)
                        + (pi.sunday_duty   * pi.per_day)
                        + ((pi.per_day/8*2.4) * pi.special_holiday)
                        - (pi.late * (pi.per_day/480))
                    )                                                                   AS gross,
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

        while ($row = $result->fetch_assoc()) {
            $payroll_type     = isset($row['payroll_type']) ? (int) $row['payroll_type'] : 0;
            $perMinute        = $row['per_day'] / (8 * 60);
            $allowance_total  = $row['allowance_amount'] * $row['allowance_days'];
            $absent_amount    = $row['absent'] * $row['per_day'];
            $overtime_amount  = $row['ot'] * $row['ot_rate'];
            $late_amount      = $row['late'] * $perMinute;
            $legal_amount     = $row['legal_holiday'] * $row['per_day'];
            $sunday_amount    = $row['sunday_duty'] * $row['per_day'];
            $special_amount   = (($row['per_day'] / 8) * 2.4) * $row['special_holiday'];

            // Pay basis is now per-employee (rate_type), frozen on the payroll item at calc.
            // 'fixed' shares the salary-based formula but always has absent=0 (full pay,
            // no attendance). payroll_type == 5 (whole-run monthly) is a legacy override.
            $rt = $row['rate_type'] ?? 'daily';
            $is_monthly = $rt === 'monthly' || $rt === 'fixed' || ($payroll_type == 5);
            if ($is_monthly) {
                // Monthly/fixed rate: basic is the fixed monthly salary share (semi-monthly ÷2),
                // and unpaid absences are deducted at the daily rate (0 for fixed). Gross folds in premiums.
                $total_basic_rate = $row['basic_pay'];
                $total_amount     = ($total_basic_rate + $allowance_total - $absent_amount) / 2;
                $gross            = $total_amount + $overtime_amount + $legal_amount + $sunday_amount + $special_amount - $late_amount;
            } else {
                // Daily rate: Total Basic Rate = (days present + approved paid-leave days) ×
                // rate per day — daily staff are paid for approved paid leave. gross = basic +
                // overtime + allowance − late (matches table-2 in the page).
                $total_basic_rate = ($row['present'] + ($row['paid_leave'] ?? 0)) * $row['per_day'];
                $total_amount     = ($total_basic_rate + $allowance_total - $absent_amount) / 2;
                $gross            = ($total_basic_rate + $overtime_amount + $allowance_total) - $late_amount;
            }

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
            'sss_fund', 'jei_advances', 'jcc_advances', 'tax', 'other_deduction',
            'adjustment', 'adjustment_remarks', 'net',
            'contribution', 'deduction', 'loan', 'refund',
        ];
        if (!in_array($field, $allowed_fields, true)) {
            throw new Exception('Unknown payroll item field: ' . $field);
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
        $ids = $_POST['id'];
        $nets = $_POST['net'];
        foreach ($ids as $index =>  $k) {
            $id = $k;
            $net =  $nets[$index];

            $query_update = "UPDATE payroll_items SET net = ? WHERE id = ?";
            $stmt3 = $this->db->prepare($query_update);
            if ($stmt3 === false) {
                throw new Exception('Failed to prepare the statement: ' . $this->db->error);
            }
            $stmt3->bind_param("si", $net, $id);
            try {
                $stmt3->execute();
            } catch (Exception $e) {
            }
        }

        $this->db->commit();
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
        $data .= ", loan_amount = $loan_amount ";
        $data .= ", loan_status = $loan_status ";
        $data .= ", loan_type = $loan_type ";
        $data .= ", loan_balance = $loan_balance ";
        $data .= ", damount = $damount ";
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
        $restMap = [];
        $rq = $this->db->prepare("SELECT employee_id, effective_from, effective_to, rest_days
            FROM employee_schedules
            WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
            ORDER BY effective_from DESC");
        $rq->bind_param('ss', $pay['date_to'], $pay['date_from']);
        $rq->execute();
        $rr = $rq->get_result();
        while ($rw = $rr->fetch_assoc()) $restMap[$rw['employee_id']][] = $rw;

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
        foreach ($data['rows'] as $r) {
            $iid = $r['id'];
            $eid = $emps[$iid] ?? 0;
            $nm = $names[$iid] ?? ('#' . $iid);
            $net = (float) $r['net'];
            $isFixed = ($r['rate_type'] ?? 'daily') === 'fixed';

            if ($net <= 0) $negative[] = ['name' => $nm, 'net' => round($net, 2)];
            if (!$isFixed && (float) $r['present'] <= 0) $zero[] = ['name' => $nm];
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
                    if (!$this->isRestDay($restMap[$eid] ?? [], $symd) && empty($holidayDates[$symd])) $expected++;
                }
                $counted = (float) $r['present'] + (float) ($r['paid_leave'] ?? 0) + (float) $r['absent'];
                $miss = $expected - $counted;
                if ($miss >= 1) {
                    $missing[] = ['name' => $nm, 'expected' => $expected, 'counted' => round($counted, 2), 'missing' => round($miss, 1)];
                }
            }
        }
        usort($swings, fn($a, $b) => abs($b['pct']) <=> abs($a['pct']));

        return [
            'result' => true,
            'total' => count($data['rows']),
            'threshold' => $threshold,
            'prev_label' => $prev ? date('M j', strtotime($prev['date_from'])) . '–' . date('M j, Y', strtotime($prev['date_to'])) : null,
            'negative' => $negative,
            'zero_days' => $zero,
            'swings' => $swings,
            'missing' => $missing,
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
            $files_types = ['present' => 'No. of Days', 'per_day' => 'Basic Rate', 'allowance_amount' => 'Allowance', 'ot' => "Overtime", 'ot_rate' => "Overtime Rate", 'under_time' => "Undertime", "other_deduction" => "Other Deduction", 'late' => 'Late', 'absent' => 'Absent', 'legal_holiday' => 'Legal Holiday', 'sunday_duty' => "Rest Day Duty", "special_holiday" => 'Special Holiday', "sss_fund" => "SSS PROVIDENT FUND", "jei_advances" => "JEI ADVANCE", "jcc_advances" => "JCC ADVANCES", "tax" => "Tax", 'allowance_days' => "Allowance No. dys", 'adjustment' => "Adjustment", 'adjustment_remarks' => "Adjustment Remarks"];
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

        if ($name === '') {
            return ['result' => false, 'message' => 'Leave type name is required.'];
        }
        if ($days_allowed < 0) {
            return ['result' => false, 'message' => 'Days allowed cannot be negative.'];
        }

        if ($id === 0) {
            $stmt = $this->db->prepare("INSERT INTO leave_types (name, days_allowed, is_paid, description, status, carryover, carryover_cap) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('siisiid', $name, $days_allowed, $is_paid, $description, $status, $carryover, $carry_cap);
        } else {
            $stmt = $this->db->prepare("UPDATE leave_types SET name = ?, days_allowed = ?, is_paid = ?, description = ?, status = ?, carryover = ?, carryover_cap = ? WHERE id = ?");
            $stmt->bind_param('siisiidi', $name, $days_allowed, $is_paid, $description, $status, $carryover, $carry_cap, $id);
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
            if ($id === 0) {
                $new_id = $this->db->insert_id;
                $info = $this->leaveInfo($new_id);
                // Notify the FIRST approver in the configured chain (scoped to the
                // employee's department) that a new request needs their review.
                $stages   = leave_stages();
                $firstKey = array_key_first($stages);
                $firstCfg = $stages[$firstKey];
                $this->notifyRoleForEmployee((int) $firstCfg['role'], $employee_id, 'New leave request', "{$info['emp']} filed a {$info['type']} ({$info['dur']} day/s). Needs {$firstCfg['label']} approval.", $firstCfg['icon'] ?? 'ri-calendar-event-line', 'warning', 'index.php?page=leaves');
            }
            return ['result' => true, 'message' => $id === 0 ? 'Leave request filed. Sent to HR for review.' : 'Leave request updated.'];
        }
        return ['result' => false, 'message' => $stmt->error];
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

        // Only the role that owns this stage may act on it. Administrator (role 1)
        // and every other role stay view-only.
        if ($role !== (int) $cfg['role']) {
            return ['result' => false, 'message' => 'You are not allowed to act on the ' . $cfg['label'] . ' approval.'];
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
        // Only leave-workflow roles may delete (Administrator role 1 is view-only).
        if (!in_array($role, [8, 9, 10], true)) {
            return ['result' => false, 'message' => 'You are not allowed to delete leave requests.'];
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

    // HR / Admin / Dept Head: change an employee's leave credits for a leave type.
    // mode = 'set' (absolute), 'add' (+amount) or 'deduct' (−amount, floored at 0).
    // add/deduct require a reason; every change is written to leave_credit_history.
    function save_leave_credit()
    {
        $employee_id   = (int) ($_POST['employee_id'] ?? 0);
        $leave_type_id = (int) ($_POST['leave_type_id'] ?? 0);
        $mode          = in_array(($_POST['mode'] ?? 'set'), ['set', 'add', 'deduct'], true) ? $_POST['mode'] : 'set';
        // 'amount' = delta for add/deduct or the absolute for set; falls back to the
        // legacy 'credits' field so the plain SET editor keeps working unchanged.
        $amount        = (float) ($_POST['amount'] ?? $_POST['credits'] ?? 0);
        $reason        = trim($_POST['reason'] ?? '');

        // Server-side guard matching the UI: only Admin (1), Dept Head (8) and HR (9)
        // may change credits; scoped Heads only within their own department.
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!in_array($role, [1, 8, 9], true)) {
            return ['result' => false, 'message' => 'You are not allowed to change leave credits.'];
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

        // Current value for this year (defaults to the type's standard entitlement when unset).
        $cur = $this->db->query("
            SELECT COALESCE(c.credits, lt.days_allowed) AS credits
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
                SELECT CONCAT(e.firstname,' ',e.lastname) AS emp, lt.name AS type
                FROM employee e JOIN leave_types lt ON lt.id = $leave_type_id WHERE e.id = $employee_id
            ")->fetch_assoc();
            $emp  = $meta['emp'] ?? 'Employee';
            $type = $meta['type'] ?? 'leave';
            $who  = 'Someone';
            if ($changer) {
                $wq = $this->db->query("SELECT name FROM users WHERE id = " . (int) $changer)->fetch_assoc();
                $who = $wq['name'] ?? 'A user';
            }
            $fmt  = fn($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
            $verb = $mode === 'add' ? 'added ' . $fmt($amount) . ' to' : ($mode === 'deduct' ? 'deducted ' . $fmt($amount) . ' from' : 'set');
            $msg  = "$who $verb $emp's $type balance: " . $fmt($old_credits) . " → " . $fmt($new_credits) . " day(s)."
                  . ($reason !== '' ? " Reason: $reason" : '');

            // Notify HR + Admins (so any balance change is visible/auditable).
            $this->notifyRole(1, 'Leave balance updated', $msg, 'ri-coins-line', 'info', 'index.php?page=leave_balances&emp=' . $employee_id);
            $this->notifyRole(9, 'Leave balance updated', $msg, 'ri-coins-line', 'info', 'index.php?page=leave_balances&emp=' . $employee_id);
        }

        $labels = ['set' => 'Balance updated', 'add' => 'Credits added', 'deduct' => 'Credits deducted'];
        return ['result' => true, 'message' => $labels[$mode] . '.'];
    }

    // HR / Admin / Dept Head: set the per-employee leave eligibility override.
    // override = '' | 'auto' (NULL, follow classification) · '1' (force allow) · '0' (force block).
    function save_leave_override()
    {
        $role = (int) ($_SESSION['login_role'] ?? 0);
        if (!in_array($role, [1, 8, 9], true)) {
            return ['result' => false, 'message' => 'You are not allowed to change leave eligibility.'];
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
        if (!in_array($role, [1, 9], true)) {   // Admin + HR only
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

                // Source-year balance (defaults to the allowance if never set).
                $src = $this->db->query("SELECT credits FROM employee_leave_credits WHERE employee_id=$eid AND leave_type_id=$tid AND year=$from_year")->fetch_assoc();
                $src_credits = $src ? (float) $src['credits'] : $allowance;

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

                // Existing target value (for the old→new audit line; defaults to allowance).
                $tgt = $this->db->query("SELECT credits FROM employee_leave_credits WHERE employee_id=$eid AND leave_type_id=$tid AND year=$to_year")->fetch_assoc();
                $old_target = $tgt ? (float) $tgt['credits'] : $allowance;

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

    /* ──────────────────────────────────────────────────────────────
     * Calendar / Holidays
     * ────────────────────────────────────────────────────────────── */

    function save_pay_settings()
    {
        $uid  = $_SESSION['login_id'] ?? null;
        $keys = ['legal_holiday_rate', 'special_holiday_rate', 'ot_regular_rate', 'ot_holiday_multiplier', 'nsd_rate', 'rest_day_rate', 'sanity_net_swing_pct'];
        // 13th month checkboxes: absent from POST = unchecked = 0.
        $flag_keys = ['th13_include_paid_leave', 'th13_include_allowance', 'th13_round_to_peso'];
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
    private function th13_setting($key, $default)
    {
        $key = $this->db->real_escape_string($key);
        $r = $this->db->query("SELECT setting_value FROM pay_settings WHERE setting_key = '$key'");
        $row = $r ? $r->fetch_assoc() : null;
        return $row !== null ? (float) $row['setting_value'] : $default;
    }

    /**
     * Scan every payroll of the given year and (re)build the draft rows:
     * per employee, BASIC salary actually paid per cutoff, summed, ÷ 12.
     *   daily        → (present [+ paid_leave]) × per_day  [+ allowance]
     *   monthly/fixed→ (basic_pay [+ allowance] − absent × per_day) / 2
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
                        THEN (pi.basic_pay $allow_term - (pi.absent * pi.per_day)) / 2
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
        $stmt = $this->db->prepare(
            "UPDATE DTR_details SET logs=?, work_hours=?, overtime=?, late=?, undertime=?,
             day_type=?, nsd_hours=?, is_complete=?, attendance_type='manual' WHERE id=?"
        );
        $stmt->bind_param(
            'sddddsdii',
            $json_logs,
            $work_hours,
            $overtime,
            $late,
            $undertime,
            $day_type,
            $nsd_hours,
            $is_complete,
            $id
        );
        return ['result' => $stmt->execute(), 'message' => $stmt->error ?: 'Saved'];
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

        // Explicit id list (checkbox selection) takes precedence.
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt   = $this->db->prepare("UPDATE DTR_details SET $setSql WHERE id IN ($placeholders)");
            $types  = 'isi' . str_repeat('i', count($ids));
            $params = array_merge([$decision, $noteSql, $decider], $ids);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) return ['result' => false, 'message' => $stmt->error];
            return ['result' => true, 'message' => 'Records updated', 'affected' => $stmt->affected_rows];
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
            $stmt = $this->db->prepare("UPDATE DTR_details SET $setSql WHERE ddtr_id = ? AND DATE(date_time) = ? AND status = 0" . $cleanSql);
            $stmt->bind_param('isiis', $decision, $noteSql, $decider, $ddtr_id, $date);
        } else {
            $stmt = $this->db->prepare("UPDATE DTR_details SET $setSql WHERE ddtr_id = ? AND status = 0" . $cleanSql);
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
     * day math (dtr_compute_day: current schedules + holiday calendar).
     * Needed for batches generated before schedules existed, or after a
     * schedule assignment changes mid-period.
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
        $batch = $this->db->query("SELECT id, status FROM DTR WHERE id = $ddtr_id")->fetch_assoc();
        if (!$batch) return ['result' => false, 'message' => 'Batch not found'];
        if ((int)$batch['status'] === 2) {
            return ['result' => false, 'message' => 'This batch is final-approved — its figures are locked.'];
        }

        $res = $this->db->query("SELECT id, employee_id, date_time, work_hours, overtime, undertime,
                                        late, nsd_hours, day_type, status, logs
                                 FROM DTR_details WHERE ddtr_id = $ddtr_id");
        $scanned = $changed = $repending = 0;

        $this->db->begin_transaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE DTR_details SET work_hours=?, overtime=?, undertime=?, late=?, nsd_hours=?, day_type=?, is_complete=? WHERE id=?"
            );
            $updPend = $this->db->prepare(
                "UPDATE DTR_details SET work_hours=?, overtime=?, undertime=?, late=?, nsd_hours=?, day_type=?, is_complete=?,
                 status=0, decision_note=NULL, decided_by=NULL, decided_at=NULL WHERE id=?"
            );
            while ($row = $res->fetch_assoc()) {
                $scanned++;
                $ts = [];
                foreach ((json_decode($row['logs']) ?: []) as $lg) {
                    $t = strtotime($lg->dateTime ?? '');
                    if ($t !== false) $ts[] = $t;
                }
                $c = dtr_compute_day($this->db, (int)$row['employee_id'], $row['date_time'], $ts);

                $same = abs($c['work_hours'] - (float)$row['work_hours']) < 0.005
                     && abs($c['overtime']   - (float)$row['overtime'])   < 0.005
                     && abs($c['undertime']  - (float)$row['undertime'])  < 0.005
                     && abs($c['late']       - (float)$row['late'])       < 0.005
                     && abs($c['nsd_hours']  - (float)$row['nsd_hours'])  < 0.005
                     && $c['day_type'] === $row['day_type'];
                if ($same) continue;

                $changed++;
                $rowId    = (int)$row['id'];
                $toPend   = ((int)$row['status'] === 1);   // only approved rows re-open
                $stmt     = $toPend ? $updPend : $upd;
                if ($toPend) $repending++;
                $stmt->bind_param('dddddsii', $c['work_hours'], $c['overtime'], $c['undertime'],
                                  $c['late'], $c['nsd_hours'], $c['day_type'], $c['is_complete'], $rowId);
                if (!$stmt->execute()) throw new Exception('Row ' . $rowId . ': ' . $stmt->error);
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['result' => false, 'message' => 'Recompute failed — nothing was changed. ' . $e->getMessage()];
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
}
