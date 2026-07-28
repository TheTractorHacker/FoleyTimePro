-- 013_open_punch_unique_constraint.sql
-- Enforce "at most one open punch per employee" at the DB level so a
-- concurrent double-clockin (two simultaneous requests both seeing zero
-- open punches during the SELECT-then-INSERT window) can't create two
-- open punches. Cheapest defense: UNIQUE on (EmployeeID, IsOpen) where
-- IsOpen is a generated column that yields 1 for open punches and NULL
-- for closed. MySQL/MariaDB treats NULLs as distinct in UNIQUE indexes,
-- so any number of closed rows per employee are fine — only the "one
-- open" invariant is enforced.

-- Guard against re-runs.
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'timepunches'
      AND COLUMN_NAME  = 'IsOpen'
);

SET @stmt := IF(@has_col = 0,
    'ALTER TABLE timepunches
        ADD COLUMN IsOpen TINYINT(1)
            GENERATED ALWAYS AS (CASE WHEN TimeOUT IS NULL THEN 1 ELSE NULL END) VIRTUAL',
    'DO 0');
PREPARE p FROM @stmt;
EXECUTE p;
DEALLOCATE PREPARE p;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'timepunches'
      AND INDEX_NAME   = 'uk_open_per_employee'
);

SET @stmt := IF(@has_idx = 0,
    'ALTER TABLE timepunches ADD UNIQUE KEY uk_open_per_employee (EmployeeID, IsOpen)',
    'DO 0');
PREPARE p FROM @stmt;
EXECUTE p;
DEALLOCATE PREPARE p;
