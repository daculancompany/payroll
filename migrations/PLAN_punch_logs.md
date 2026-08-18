# Migration plan — `punch_logs` as the single source of truth for punches

**Status:** planned, not started. Schedule this **after the current cutoff closes**
(batch 13, 2026-08-16 → 2026-08-31). Do not start it mid-cutoff.

---

## The problem it solves

A punch exists in exactly one place: as an element of the JSON in
`DTR_details.logs`. `time_logs` and `attendance` are empty legacy tables and are
not written by anything.

That means **which day a punch belongs to is encoded by which row's JSON it sits
in**. The assignment *is* the storage. Consequences, all of which have bitten:

- Re-dating a punch requires surgically moving JSON between two rows
  (`Action::repairPunchDates`, ~200 lines, two mirrored directions).
- A day cannot "have no punches" — the row already exists and is tied to a
  batch, so emptied rows linger as blanks (see `DTR_details` id 28051).
- Pushing a punch back to a day with no row means *opening* a row, which means
  resolving a batch, which means the cutoff-range guard in `repairPunchDates`.
- No punch identity → no audit trail of a move, no natural dedup key.
- Double-scan protection is a 300-second window check in
  `Action::save_biometric_attendance` instead of a database constraint.

## Target schema

```sql
CREATE TABLE punch_logs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  punched_at    DATETIME NOT NULL,      -- the instant. NEVER rewritten.
  source        ENUM('bio','manual','kiosk','import') NOT NULL DEFAULT 'bio',
  site_id       INT NULL,
  authorized_by VARCHAR(80) NULL,       -- manual entries
  work_date     DATE NULL,              -- the SHIFT DAY it counts toward
  assigned_by   INT NULL,               -- who/what last re-dated it
  assigned_at   DATETIME NULL,
  voided_at     DATETIME NULL,          -- soft delete — never lose evidence
  void_reason   VARCHAR(255) NULL,
  UNIQUE KEY uq_punch (employee_id, punched_at),
  KEY idx_day (employee_id, work_date),
  CONSTRAINT fk_punch_emp FOREIGN KEY (employee_id) REFERENCES employee (id)
);
```

The split that matters: **`punched_at` is the fact and never changes;
`work_date` is the interpretation and is free to change.** `DTR_details` keeps
what it should own — computed figures and the frozen shift stamp.

Re-dating then collapses to:

```sql
UPDATE punch_logs SET work_date = ?, assigned_by = ?, assigned_at = NOW() WHERE id = ?
```

Both directions of `repairPunchDates` become the same one-line operation, and
most of that method deletes.

## Migration surface

**16 punch-parsing sites across 12 files** (`json_decode` on a `logs` value):

| File | sites |
|---|---|
| `admin_class.php` | 4 |
| `employee-portal.php` | 2 |
| `db_connect.php` | 1 |
| `dtr-employee-server.php` | 1 |
| `emp-portal-ajax.php` | 1 |
| `payroll_calculations.php` | 1 |
| `home.php` | 1 |
| `daily-board.php` | 1 |
| `biometric-dtr.php` | 1 |
| `attendance-server.php` | 1 |
| `attendance-portal-server.php` | 1 |
| `component/dtr_employee_card.php` | 1 |

## Staged rollout

Each step is independently shippable and reversible. No big-bang cutover.

### 1. Create + backfill
- Add the table.
- Backfill from every `DTR_details.logs` JSON: one row per punch, with
  `work_date` = the source row's `date_time`, `source` = the entry's `type`.
- Verify: `COUNT(punch_logs)` equals the total punch count across all `logs`
  JSON, and every `(employee_id, punched_at)` is unique. Resolve any duplicate
  before adding the unique key.

### 2. Dual-write
- `Action::save_biometric_attendance` writes the new row **and** the existing
  JSON column.
- `repairPunchDates` updates `work_date` **and** moves the JSON.
- Nothing reads the new table yet. Every existing reader is untouched, so this
  step cannot break anything.
- Let it run for at least one full cutoff, then verify the two representations
  still agree (a scheduled diff check is worth writing here).

### 3. Move readers, one at a time
Order by blast radius, smallest first — verify each against the JSON it
replaces before moving on:

1. `component/dtr_employee_card.php`, `daily-board.php`, `home.php` (display only)
2. `attendance-server.php`, `attendance-portal-server.php`, `biometric-dtr.php`
3. `dtr-employee-server.php`, `emp-portal-ajax.php`, `employee-portal.php` (the DTR sheet)
4. `db_connect.php` → `dtr_compute_day` (the figures)
5. `admin_class.php` → recompute + `payroll_calculations.php` (money — last)

### 4. Retire the JSON
- Stop writing `DTR_details.logs`.
- Keep the column for one cutoff as a fallback, then drop it.
- Delete the JSON-moving half of `repairPunchDates`; keep the date-assignment
  rules, which are the part that carries real domain knowledge.

## Invariant to preserve throughout

The rule `repairPunchDates` encodes today, and which must survive the migration:

> The logs must end up where ingestion would have put them had the roster been
> right at the time — same overnight predicate, same 12-hour post-shift OT
> ceiling.

A repaired day must stay indistinguishable from one recorded correctly.

## Also worth doing while in here

`DTR_details.notes` currently doubles as (a) the generated shift label written
by ingestion and (b) a free-text admin note. `Action::refreshShiftNote` has to
tell them apart by string-matching every label the app can generate, which is
fragile. Splitting these into two columns would remove that guesswork.
