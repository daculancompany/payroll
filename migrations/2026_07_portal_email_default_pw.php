<?php
/**
 * One-off setup: give every ACTIVE employee a portal login where
 *   username = a (random, unique) email address
 *   password = the default word "password"  (bcrypt-hashed)
 *
 * Existing employee_portal_accounts rows are reused/updated; missing ones
 * are created. Run from CLI:  php migrations/2026_07_portal_email_default_pw.php
 */
$conn = require __DIR__ . '/../db_connect.php';

$DEFAULT_PASSWORD = 'password';
$DOMAIN           = 'example.com';

$res = $conn->query("SELECT id, employee_no, firstname, lastname FROM employee WHERE status = 1 ORDER BY id");

$seen = [];      // track generated emails so they stay UNIQUE
$rows = [];

function slug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return $s;
}

while ($e = $res->fetch_assoc()) {
    $base = slug($e['firstname']) . '.' . slug($e['lastname']);
    if ($base === '.' || $base === '') $base = 'user' . $e['id'];
    // 4-char random suffix keeps it "random" and guarantees uniqueness.
    do {
        $rand  = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 4);
        $email = $base . '.' . $rand . '@' . $DOMAIN;
    } while (isset($seen[$email]));
    $seen[$email] = true;

    $rows[] = ['id' => (int)$e['id'], 'employee_no' => $e['employee_no'], 'email' => $email];
}

$hash = password_hash($DEFAULT_PASSWORD, PASSWORD_BCRYPT); // bcrypt salts per-call; one hash is fine

// employee_id is a plain index (not unique), so decide UPDATE vs INSERT per row.
$existing = [];
$er = $conn->query("SELECT employee_id FROM employee_portal_accounts");
while ($x = $er->fetch_assoc()) $existing[(int)$x['employee_id']] = true;

$ins = $conn->prepare(
    "INSERT INTO employee_portal_accounts (employee_id, username, password, is_active, must_change)
     VALUES (?, ?, ?, 1, 1)"
);
$upd = $conn->prepare(
    "UPDATE employee_portal_accounts
     SET username = ?, password = ?, is_active = 1, must_change = 1
     WHERE employee_id = ?"
);

$created = 0; $updated = 0;
foreach ($rows as $r) {
    if (isset($existing[$r['id']])) {
        $upd->bind_param('ssi', $r['email'], $hash, $r['id']);
        $upd->execute();
        $updated++;
        $tag = 'updated';
    } else {
        $ins->bind_param('iss', $r['id'], $r['email'], $hash);
        $ins->execute();
        $created++;
        $tag = 'created';
    }
    printf("%-8s  %-32s  %s\n", $r['employee_no'], $r['email'], $tag);
}

echo "\nDone. {$updated} updated, {$created} created. Everyone signs in with the email above (or their employee_no) and password: {$DEFAULT_PASSWORD}\n";
