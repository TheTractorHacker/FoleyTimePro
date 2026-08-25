-- Migration 017: add 'Unpaid' as a real time-off request type.
--
-- The Android app's request dialog now offers PTO / Sick / Unpaid. There was
-- no backend value for "unpaid" — RequestType's enum was Vacation/Sick/
-- Personal/Other, so the client had no honest way to submit it (mapping
-- Unpaid onto 'Other' would have made every future 'Other' request ambiguous
-- between "genuinely other" and "unpaid", and admins reviewing requests would
-- see 'Other' with no way to tell which).
--
-- Deliberately NOT added to leave_balances / leave_accrual_policy's LeaveType
-- enum: unpaid time off doesn't accrue or draw down a paid balance, so it has
-- no ledger row to speak of. functions/leave_balance.php's getAllLeaveBalances()
-- iterates its own hardcoded ['Vacation','Sick','Personal','Other'] list and
-- is intentionally left alone here — Unpaid should never appear in a balance
-- read.
--
-- MODIFY COLUMN is naturally idempotent (re-declaring the same enum twice is a
-- no-op), unlike the ADD COLUMN IF NOT EXISTS pattern migrations 011/015 use.

ALTER TABLE time_off_requests
    MODIFY COLUMN RequestType ENUM('Vacation','Sick','Personal','Other','Unpaid')
        NOT NULL DEFAULT 'Vacation';
