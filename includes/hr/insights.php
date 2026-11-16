<?php
/**
 * HR reminders and reports — read-only, computed from the 201 file and the HR
 * module tables. Plain functions so a dashboard widget can call them without
 * building a controller.
 *
 * Everything here looks at ACTIVE employees (employee.status = 1) only: a
 * separated employee's expiring PRC licence is nobody's task.
 *
 * Every query tolerates a missing table (the migration not run yet) by
 * returning nothing for that part, never an error on the dashboard.
 */

if (!function_exists('hr_table_exists')) {
    function hr_table_exists(mysqli $db, $table)
    {
        static $seen = [];
        if (!isset($seen[$table])) {
            $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
            $seen[$table] = (bool) ($r && $r->num_rows);
        }
        return $seen[$table];
    }

    function hr_rows(mysqli $db, $sql)
    {
        $res = $db->query($sql);
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }

    /** Name expression used by every list below. */
    function hr_name_sql($alias = 'e')
    {
        return "TRIM(CONCAT($alias.firstname, ' ', $alias.lastname))";
    }

    /**
     * Things HR should act on, grouped. Each group:
     *   key, title, icon, tone (danger|warn|info|good), items[] of
     *   {employee_id, name, detail, date, days}
     * `days` is days from today (negative = already past).
     *
     * @param int $horizon days ahead to look for expiries
     */
    function hr_alerts(mysqli $db, $horizon = 60)
    {
        $horizon = max(1, min(365, (int) $horizon));
        $name    = hr_name_sql();
        $groups  = [];
        $add = function ($key, $title, $icon, $tone, array $items, $link = null) use (&$groups) {
            $groups[] = compact('key', 'title', 'icon', 'tone', 'items', 'link');
        };

        if (hr_table_exists($db, 'employee_profile')) {
            // Licences and HMO cards: expired, or expiring inside the horizon.
            foreach ([
                ['prc', 'prc_expiry_date', 'PRC licences expiring', 'ri-award-line', "CONCAT(COALESCE(p.prc_profession, 'PRC'), ' ', COALESCE(p.prc_license_no, ''))"],
                ['hmo', 'hmo_expiry_date', 'HMO cards expiring', 'ri-heart-pulse-line', "COALESCE(p.hmo_provider, 'HMO')"],
            ] as $spec) {
                list($key, $col, $title, $icon, $detail) = $spec;
                $items = hr_rows($db, "
                    SELECT e.id AS employee_id, $name AS name, TRIM($detail) AS detail, p.$col AS date,
                           DATEDIFF(p.$col, CURDATE()) AS days
                    FROM employee_profile p JOIN employee e ON e.id = p.employee_id AND e.status = 1
                    WHERE p.$col IS NOT NULL AND p.$col <= DATE_ADD(CURDATE(), INTERVAL $horizon DAY)
                    ORDER BY p.$col LIMIT 100");
                $add($key, $title, $icon, 'warn', $items);
            }

            // Probationary employees reaching six months (or past it) with no
            // regularization recorded. Six months is the Labor Code ceiling.
            $add('regularization', 'Due for regularization', 'ri-user-star-line', 'info', hr_rows($db, "
                SELECT e.id AS employee_id, $name AS name,
                       CONCAT('Hired ', DATE_FORMAT(p.date_hired, '%b %e, %Y')) AS detail,
                       DATE_ADD(p.date_hired, INTERVAL 6 MONTH) AS date,
                       DATEDIFF(DATE_ADD(p.date_hired, INTERVAL 6 MONTH), CURDATE()) AS days
                FROM employee_profile p JOIN employee e ON e.id = p.employee_id AND e.status = 1
                WHERE p.employment_status = 'Probationary' AND p.date_hired IS NOT NULL
                  AND p.regularization_date IS NULL
                  AND DATE_ADD(p.date_hired, INTERVAL 6 MONTH) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ORDER BY date LIMIT 100"));

            // Work anniversaries in the next 30 days (whole years only).
            $add('anniversaries', 'Work anniversaries (next 30 days)', 'ri-medal-line', 'good', hr_rows($db, "
                SELECT employee_id, name, CONCAT(years, ' year', IF(years = 1, '', 's')) AS detail, date, DATEDIFF(date, CURDATE()) AS days
                FROM (
                    SELECT e.id AS employee_id, $name AS name, p.date_hired,
                           TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE())
                             + (DATE_ADD(p.date_hired, INTERVAL TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE()) YEAR) < CURDATE()) AS years,
                           DATE_ADD(p.date_hired, INTERVAL TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE())
                             + (DATE_ADD(p.date_hired, INTERVAL TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE()) YEAR) < CURDATE()) YEAR) AS date
                    FROM employee_profile p JOIN employee e ON e.id = p.employee_id AND e.status = 1
                    WHERE p.date_hired IS NOT NULL AND p.date_hired < CURDATE()
                ) a
                WHERE years >= 1 AND date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ORDER BY date LIMIT 100"));
        }

        // Birthdays in the next 30 days — employee.bday is on every record.
        $add('birthdays', 'Birthdays (next 30 days)', 'ri-cake-2-line', 'good', hr_rows($db, "
            SELECT employee_id, name, CONCAT('Turns ', TIMESTAMPDIFF(YEAR, bday, date)) AS detail, date, DATEDIFF(date, CURDATE()) AS days
            FROM (
                SELECT e.id AS employee_id, $name AS name, e.bday,
                       DATE_ADD(e.bday, INTERVAL TIMESTAMPDIFF(YEAR, e.bday, CURDATE())
                         + (DATE_ADD(e.bday, INTERVAL TIMESTAMPDIFF(YEAR, e.bday, CURDATE()) YEAR) < CURDATE()) YEAR) AS date
                FROM employee e
                WHERE e.status = 1 AND e.bday IS NOT NULL AND e.bday > '1900-01-01' AND e.bday < CURDATE()
            ) b
            WHERE date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY date LIMIT 100"));

        if (hr_table_exists($db, 'hr_documents')) {
            $add('documents', 'Documents expiring', 'ri-file-warning-line', 'warn', hr_rows($db, "
                SELECT e.id AS employee_id, $name AS name, CONCAT(d.category, ': ', d.title) AS detail,
                       d.expiry_date AS date, DATEDIFF(d.expiry_date, CURDATE()) AS days
                FROM hr_documents d JOIN employee e ON e.id = d.employee_id AND e.status = 1
                WHERE d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $horizon DAY)
                ORDER BY d.expiry_date LIMIT 100"));

            $add('doc_review', 'Documents waiting for HR to verify', 'ri-file-search-line', 'info', hr_rows($db, "
                SELECT e.id AS employee_id, $name AS name, CONCAT(d.category, ': ', d.title) AS detail,
                       DATE(d.created_at) AS date, DATEDIFF(DATE(d.created_at), CURDATE()) AS days
                FROM hr_documents d JOIN employee e ON e.id = d.employee_id
                WHERE d.source = 'employee' AND d.verified_at IS NULL
                ORDER BY d.created_at LIMIT 100"));
        }

        if (hr_table_exists($db, 'hr_trainings')) {
            $add('certificates', 'Training certificates expiring', 'ri-graduation-cap-line', 'warn', hr_rows($db, "
                SELECT e.id AS employee_id, $name AS name, t.title AS detail,
                       t.expiry_date AS date, DATEDIFF(t.expiry_date, CURDATE()) AS days
                FROM hr_trainings t JOIN employee e ON e.id = t.employee_id AND e.status = 1
                WHERE t.expiry_date IS NOT NULL AND t.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $horizon DAY)
                ORDER BY t.expiry_date LIMIT 100"));
        }

        if (hr_table_exists($db, 'hr_memos')) {
            // Unacknowledged for more than three days — a nudge, not a breach.
            $add('memos', 'Memos not yet acknowledged', 'ri-file-text-line', 'warn', hr_rows($db, "
                SELECT e.id AS employee_id, $name AS name, CONCAT(m.memo_type, ': ', m.subject) AS detail,
                       m.issued_date AS date, DATEDIFF(m.issued_date, CURDATE()) AS days
                FROM hr_memos m JOIN employee e ON e.id = m.employee_id
                WHERE m.requires_ack = 1 AND m.acknowledged_at IS NULL AND m.status = 'open'
                  AND m.issued_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                ORDER BY m.issued_date LIMIT 100"));
        }

        if (hr_table_exists($db, 'hr_doc_requests')) {
            $add('requests', 'Document requests waiting', 'ri-file-paper-2-line', 'info', hr_rows($db, "
                SELECT e.id AS employee_id, $name AS name,
                       IF(r.request_type = 'coe_comp', 'COE with compensation', 'Certificate of Employment') AS detail,
                       DATE(r.created_at) AS date, DATEDIFF(DATE(r.created_at), CURDATE()) AS days
                FROM hr_doc_requests r JOIN employee e ON e.id = r.employee_id
                WHERE r.status = 'pending' ORDER BY r.created_at LIMIT 100"), 'index.php?page=hr-requests');
        }

        if (hr_table_exists($db, 'hr_clearances')) {
            $add('clearances', 'Open clearances', 'ri-logout-box-r-line', 'info', hr_rows($db, "
                SELECT e.id AS employee_id, $name AS name,
                       CONCAT(c.separation_type, ' — ',
                              (SELECT COUNT(*) FROM hr_clearance_items i WHERE i.clearance_id = c.id AND i.cleared = 1), '/',
                              (SELECT COUNT(*) FROM hr_clearance_items i WHERE i.clearance_id = c.id), ' signed') AS detail,
                       c.separation_date AS date, DATEDIFF(c.separation_date, CURDATE()) AS days
                FROM hr_clearances c JOIN employee e ON e.id = c.employee_id
                WHERE c.status = 'open' ORDER BY c.separation_date LIMIT 100"), 'index.php?page=hr-offboarding');
        }

        return $groups;
    }

    /** Count of alert items that need ACTION (birthdays/anniversaries excluded). */
    function hr_alert_action_count(array $groups)
    {
        $n = 0;
        foreach ($groups as $g) {
            if ($g['tone'] !== 'good') $n += count($g['items']);
        }
        return $n;
    }

    /**
     * Headcount and workforce breakdowns for the HR Overview.
     * Every breakdown lists a "Not set" bucket so missing 201 data is visible
     * instead of silently shrinking the totals.
     */
    function hr_reports(mysqli $db)
    {
        $out = [
            'active'        => 0,
            'by_department' => [],
            'by_status'     => [],
            'by_sex'        => [],
            'by_age'        => [],
            'by_tenure'     => [],
            'monthly'       => [],
            'profile_fill'  => 0,
        ];

        $r = hr_rows($db, "SELECT COUNT(*) AS n FROM employee WHERE status = 1");
        $out['active'] = (int) ($r[0]['n'] ?? 0);

        $out['by_department'] = hr_rows($db, "
            SELECT COALESCE(d.name, 'No department') AS label, COUNT(*) AS n
            FROM employee e LEFT JOIN department d ON d.id = e.department_id
            WHERE e.status = 1 GROUP BY label ORDER BY n DESC, label");

        $out['by_age'] = hr_rows($db, "
            SELECT CASE
                     WHEN e.bday IS NULL OR e.bday <= '1900-01-01' THEN 'Not set'
                     WHEN TIMESTAMPDIFF(YEAR, e.bday, CURDATE()) < 25 THEN 'Under 25'
                     WHEN TIMESTAMPDIFF(YEAR, e.bday, CURDATE()) < 35 THEN '25–34'
                     WHEN TIMESTAMPDIFF(YEAR, e.bday, CURDATE()) < 45 THEN '35–44'
                     WHEN TIMESTAMPDIFF(YEAR, e.bday, CURDATE()) < 55 THEN '45–54'
                     ELSE '55 and over' END AS label,
                   COUNT(*) AS n
            FROM employee e WHERE e.status = 1
            GROUP BY label
            ORDER BY FIELD(label, 'Under 25', '25–34', '35–44', '45–54', '55 and over', 'Not set')");

        if (hr_table_exists($db, 'employee_profile')) {
            $fill = hr_rows($db, "SELECT COUNT(*) AS n FROM employee e JOIN employee_profile p ON p.employee_id = e.id
                                  WHERE e.status = 1 AND p.date_hired IS NOT NULL");
            $out['profile_fill'] = (int) ($fill[0]['n'] ?? 0);

            $out['by_status'] = hr_rows($db, "
                SELECT COALESCE(NULLIF(p.employment_status, ''), 'Not set') AS label, COUNT(*) AS n
                FROM employee e LEFT JOIN employee_profile p ON p.employee_id = e.id
                WHERE e.status = 1 GROUP BY label ORDER BY (label = 'Not set'), n DESC");

            $out['by_sex'] = hr_rows($db, "
                SELECT COALESCE(NULLIF(p.sex, ''), 'Not set') AS label, COUNT(*) AS n
                FROM employee e LEFT JOIN employee_profile p ON p.employee_id = e.id
                WHERE e.status = 1 GROUP BY label ORDER BY (label = 'Not set'), n DESC");

            $out['by_tenure'] = hr_rows($db, "
                SELECT CASE
                         WHEN p.date_hired IS NULL THEN 'Not set'
                         WHEN TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE()) < 1 THEN 'Under 1 year'
                         WHEN TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE()) < 3 THEN '1–2 years'
                         WHEN TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE()) < 5 THEN '3–4 years'
                         WHEN TIMESTAMPDIFF(YEAR, p.date_hired, CURDATE()) < 10 THEN '5–9 years'
                         ELSE '10 years and over' END AS label,
                       COUNT(*) AS n
                FROM employee e LEFT JOIN employee_profile p ON p.employee_id = e.id
                WHERE e.status = 1
                GROUP BY label
                ORDER BY FIELD(label, 'Under 1 year', '1–2 years', '3–4 years', '5–9 years', '10 years and over', 'Not set')");

            // Hires and separations per month, last 12 months, from the 201 dates.
            $months = [];
            for ($i = 11; $i >= 0; $i--) {
                $k = date('Y-m', strtotime("first day of -$i month"));
                $months[$k] = ['month' => $k, 'label' => date('M Y', strtotime($k . '-01')), 'hires' => 0, 'separations' => 0];
            }
            $from = array_key_first($months) . '-01';
            foreach (hr_rows($db, "SELECT DATE_FORMAT(date_hired, '%Y-%m') AS m, COUNT(*) AS n FROM employee_profile
                                   WHERE date_hired >= '$from' GROUP BY m") as $row) {
                if (isset($months[$row['m']])) $months[$row['m']]['hires'] = (int) $row['n'];
            }
            foreach (hr_rows($db, "SELECT DATE_FORMAT(date_separated, '%Y-%m') AS m, COUNT(*) AS n FROM employee_profile
                                   WHERE date_separated >= '$from' GROUP BY m") as $row) {
                if (isset($months[$row['m']])) $months[$row['m']]['separations'] = (int) $row['n'];
            }
            $out['monthly'] = array_values($months);
        }

        return $out;
    }
}
