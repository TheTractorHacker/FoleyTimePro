-- Per-employee leave balances
CREATE TABLE IF NOT EXISTS leave_balances (
    ID            INT AUTO_INCREMENT PRIMARY KEY,
    EmployeeID    INT           NOT NULL,
    LeaveType     ENUM('Vacation','Sick','Personal','Other') NOT NULL DEFAULT 'Vacation',
    YearNumber    YEAR          NOT NULL,
    AccruedHours  DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    UsedHours     DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    CarryoverHours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    UpdatedAt     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_type_year (EmployeeID, LeaveType, YearNumber),
    FOREIGN KEY (EmployeeID) REFERENCES users(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Accrual policy (one row per leave type)
CREATE TABLE IF NOT EXISTS leave_accrual_policy (
    ID               INT AUTO_INCREMENT PRIMARY KEY,
    LeaveType        ENUM('Vacation','Sick','Personal','Other') NOT NULL UNIQUE,
    HoursPerYear     DECIMAL(6,2) NOT NULL DEFAULT 40.00,
    MaxCarryover     DECIMAL(6,2) NOT NULL DEFAULT 40.00,
    AccrualFrequency ENUM('annual','monthly','biweekly') NOT NULL DEFAULT 'annual',
    IsActive         TINYINT(1)  NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add columns to time_off_requests if not present
ALTER TABLE time_off_requests
    ADD COLUMN IF NOT EXISTS HoursRequested DECIMAL(5,2) NULL COMMENT 'Calculated work hours for the request',
    ADD COLUMN IF NOT EXISTS AdminNotes      TEXT         NULL;

-- Default accrual policy seed data
INSERT IGNORE INTO leave_accrual_policy (LeaveType, HoursPerYear, MaxCarryover) VALUES
    ('Vacation', 80.00, 40.00),
    ('Sick',     48.00,  0.00),
    ('Personal', 24.00,  0.00),
    ('Other',     0.00,  0.00);
