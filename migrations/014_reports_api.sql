-- Migration 014: Indexes for the /api/external/v1 reporting surface
--
-- The new report endpoints (utilization, timecards, overtime, attendance,
-- time-off, schedule adherence) all share one access pattern: pull every punch
-- for a set of employees across a bounded date range, then do the hours math in
-- PHP because timepunches.TotalHours is not trustworthy. Without a composite
-- (EmployeeID, Date) index that becomes an index scan on EmployeeID followed by
-- a filesort/filter on Date for every employee in the request.
--
-- Adds:
--   1. timepunches (EmployeeID, Date) - the dominant report access pattern
--   2. timepunches (Date)             - all-employee range scans (no employee filter)
--   3. users (Office)                 - the ?office= filter, which otherwise table-scans
--
-- Deliberately NOT added (already covered — verified against Install/timeclock-schema.sql):
--   * time_off_requests: idx_dates (StartDate, EndDate), idx_status (Status) and
--     idx_employee (EmployeeID) already exist. loadApprovedTimeOff() filters
--     Status='Approved' + EmployeeID IN (...) + a date-overlap test; the optimizer
--     can use idx_employee or idx_status and the table is small (one row per
--     request, not per day), so a composite (Status, EmployeeID, StartDate) would
--     be maintenance cost for no measurable gain. Revisit if this table grows
--     past ~100k rows.
--   * leave_balances: uq_emp_type_year (EmployeeID, LeaveType, YearNumber) is a
--     UNIQUE key and already serves every lookup the balance report makes.
--   * shifts / user_shifts: user_shifts has UNIQUE(UserID) and KEY idx_shift, and
--     shifts has idx_active. loadShiftsForUsers() joins on exactly those.
--
-- Not dropped: timepunches KEY `EmployeeID` (EmployeeID) becomes a redundant
-- prefix of the new composite, but it is the index backing FK timepunches_ibfk_1.
-- Dropping it is a behavioral change to existing schema, which this additive
-- migration will not do.
--
-- Safe to re-run: MariaDB supports ADD INDEX IF NOT EXISTS (migration 010 relies
-- on the same guard). On stock MySQL 8 these statements must be applied manually
-- or wrapped in a procedure, as MySQL does not implement IF NOT EXISTS for indexes.

ALTER TABLE `timepunches`
  ADD INDEX IF NOT EXISTS `idx_timepunches_employee_date` (`EmployeeID`, `Date`);

ALTER TABLE `timepunches`
  ADD INDEX IF NOT EXISTS `idx_timepunches_date` (`Date`);

ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_office` (`Office`);
