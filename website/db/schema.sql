-- ═══════════════════════════════════════════════════════════
--  OxySafe – MySQL Database Schema  (v2)
--  Run once:  mysql -u root -p < schema.sql
--  Then run:  php website/db/seed.php   (creates admin user)
-- ═══════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS oxysafe_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE oxysafe_db;

-- ─── Users ───────────────────────────────────────────────────
DROP TABLE IF EXISTS sensor_data;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name                VARCHAR(100)      NOT NULL,
    username            VARCHAR(50)       NOT NULL UNIQUE,
    password            VARCHAR(255)      NOT NULL,          -- bcrypt hash
    is_admin            TINYINT(1)        NOT NULL DEFAULT 0,
    caution_threshold   SMALLINT UNSIGNED          DEFAULT NULL, -- NULL = not configured yet
    danger_threshold    SMALLINT UNSIGNED          DEFAULT NULL, -- NULL = not configured yet
    created_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

-- ─── Sensor Data ─────────────────────────────────────────────
CREATE TABLE sensor_data (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)      NOT NULL,
    temp          DECIMAL(5,2)     NOT NULL,     -- °C
    humidity      DECIMAL(5,2)     NOT NULL,     -- %
    dust_density  DECIMAL(8,2)     NOT NULL,     -- µg/m³
    aqi           DECIMAL(6,1)     NOT NULL,
    recorded_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_user_time (username, recorded_at DESC),
    FOREIGN KEY (username) REFERENCES users(username)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Auto-purge readings older than 6 hours ──────────────────
DROP EVENT IF EXISTS purge_old_readings;

CREATE EVENT purge_old_readings
    ON SCHEDULE EVERY 6 HOUR
    DO
        DELETE FROM sensor_data
        WHERE recorded_at < NOW() - INTERVAL 6 HOUR;
