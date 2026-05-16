CREATE TABLE IF NOT EXISTS quickbooks_connection (
    ID              INT AUTO_INCREMENT PRIMARY KEY,
    RealmID         VARCHAR(50)   NOT NULL UNIQUE COMMENT 'QuickBooks company ID',
    AccessToken     TEXT          NOT NULL,
    RefreshToken    TEXT          NOT NULL,
    TokenExpiry     DATETIME      NOT NULL,
    RefreshExpiry   DATETIME      NOT NULL,
    CompanyName     VARCHAR(255)  NULL,
    ConnectedAt     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quickbooks_employee_map (
    ID              INT AUTO_INCREMENT PRIMARY KEY,
    EmployeeID      INT           NOT NULL UNIQUE,
    QBEmployeeID    VARCHAR(50)   NOT NULL,
    QBDisplayName   VARCHAR(255)  NULL,
    LastSyncAt      DATETIME      NULL,
    FOREIGN KEY (EmployeeID) REFERENCES users(ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quickbooks_sync_log (
    ID          INT AUTO_INCREMENT PRIMARY KEY,
    SyncType    ENUM('employee','timesheet') NOT NULL,
    StartDate   DATE     NULL,
    EndDate     DATE     NULL,
    RecordsIn   INT      DEFAULT 0,
    RecordsOk   INT      DEFAULT 0,
    RecordsFail INT      DEFAULT 0,
    Notes       TEXT     NULL,
    SyncedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
