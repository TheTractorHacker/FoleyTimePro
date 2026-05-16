-- Geofence zones — each zone is a named circle (lat/lng + radius)
CREATE TABLE IF NOT EXISTS geofences (
    ID            INT AUTO_INCREMENT PRIMARY KEY,
    Name          VARCHAR(100)   NOT NULL,
    Latitude      DECIMAL(10,7)  NOT NULL,
    Longitude     DECIMAL(10,7)  NOT NULL,
    RadiusMeters  INT            NOT NULL DEFAULT 200,
    OfficeID      INT            NULL,
    IsRequired    TINYINT(1)     NOT NULL DEFAULT 1,
    IsActive      TINYINT(1)     NOT NULL DEFAULT 1,
    CreatedAt     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (OfficeID) REFERENCES Offices(ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Track geofence check results per punch
ALTER TABLE timepunches
    ADD COLUMN IF NOT EXISTS GeofencePassIN         TINYINT(1) NULL COMMENT '1=pass 0=fail NULL=not enforced',
    ADD COLUMN IF NOT EXISTS GeofencePassOut        TINYINT(1) NULL,
    ADD COLUMN IF NOT EXISTS GeofencePassLunchStart TINYINT(1) NULL,
    ADD COLUMN IF NOT EXISTS GeofencePassLunchEnd   TINYINT(1) NULL;
