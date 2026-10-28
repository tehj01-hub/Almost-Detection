-- =========================================================
-- MYSQL DATABASE QUERIES FOR DATE TRACKING, VISITOR DURATION,
-- GUARD PARTNER DUTY SEGMENTATION, AND 'TRUCK' VEHICLE TYPE
-- Database: security_system
-- =========================================================

USE `security_system`;

-- ---------------------------------------------------------
-- 1. CREATE TABLE FOR VEHICLE ENTRY & EXIT DATES (DATE IN / DATE OUT)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicle_log_dates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `vehicle_log_id` INT(11) DEFAULT NULL,
    `plate_number` VARCHAR(20) NOT NULL,
    `vehicle_category` ENUM('Registered', 'Visitor') NOT NULL DEFAULT 'Visitor',
    `vehicle_type` ENUM('Car', 'Motorcycle', 'Truck') NOT NULL DEFAULT 'Car',
    `purpose` VARCHAR(255) DEFAULT NULL,
    `allowed_duration_label` VARCHAR(50) DEFAULT NULL,
    `allowed_minutes` INT(11) DEFAULT NULL,
    `date_in` DATE DEFAULT NULL,
    `time_in` DATETIME DEFAULT NULL,
    `expected_time_out` DATETIME DEFAULT NULL,
    `date_out` DATE DEFAULT NULL,
    `time_out` DATETIME DEFAULT NULL,
    `status` ENUM('IN', 'OUT') NOT NULL DEFAULT 'IN',
    `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vld_plate` (`plate_number`),
    KEY `idx_vld_date_in` (`date_in`),
    KEY `idx_vld_date_out` (`date_out`),
    KEY `idx_vld_log_id` (`vehicle_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 2. CREATE TABLE FOR CO-GUARD DUTY PAIRS (LOGIN SEGMENTATION)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `guard_duty_pairs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `primary_guard_id` VARCHAR(30) NOT NULL,
    `partner_guard_id` VARCHAR(30) DEFAULT NULL,
    `duty_date` DATE NOT NULL DEFAULT (CURRENT_DATE),
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_duty_primary` (`primary_guard_id`),
    KEY `idx_duty_partner` (`partner_guard_id`),
    KEY `idx_duty_date` (`duty_date`),
    KEY `idx_duty_open` (`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- 3. ADD 'Truck' TO ALL vehicle_type ENUM COLUMNS
-- ---------------------------------------------------------
ALTER TABLE `registered_vehicles`
    MODIFY COLUMN `vehicle_type` ENUM('Car', 'Motorcycle', 'Truck') NOT NULL;

ALTER TABLE `registered_vehicles_archive`
    MODIFY COLUMN `vehicle_type` ENUM('Car', 'Motorcycle', 'Truck') NOT NULL;

ALTER TABLE `visitor_vehicles`
    MODIFY COLUMN `vehicle_type` ENUM('Car', 'Motorcycle', 'Truck') NOT NULL;

ALTER TABLE `vehicle_logs`
    MODIFY COLUMN `vehicle_type` ENUM('Car', 'Motorcycle', 'Truck') NOT NULL;

ALTER TABLE `vehicle_logs_archive`
    MODIFY COLUMN `vehicle_type` ENUM('Car', 'Motorcycle', 'Truck') NOT NULL;

-- ---------------------------------------------------------
-- 4. ADD DATE IN / DATE OUT & DURATION COLUMNS TO VISITOR & LOG TABLES
-- ---------------------------------------------------------
ALTER TABLE `visitor_vehicles`
    ADD COLUMN IF NOT EXISTS `allowed_minutes` INT(11) DEFAULT NULL AFTER `purpose`,
    ADD COLUMN IF NOT EXISTS `date_in` DATE DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `expected_time_out` DATETIME DEFAULT NULL AFTER `time_in`,
    ADD COLUMN IF NOT EXISTS `date_out` DATE DEFAULT NULL AFTER `expected_time_out`;

ALTER TABLE `visitor_vehicles_archive`
    ADD COLUMN IF NOT EXISTS `date_in` DATE DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `date_out` DATE DEFAULT NULL AFTER `time_in`;

ALTER TABLE `vehicle_logs`
    ADD COLUMN IF NOT EXISTS `allowed_minutes` INT(11) DEFAULT NULL AFTER `purpose`,
    ADD COLUMN IF NOT EXISTS `expected_time_out` DATETIME DEFAULT NULL AFTER `allowed_minutes`,
    ADD COLUMN IF NOT EXISTS `date_in` DATE DEFAULT NULL AFTER `recognition_method`,
    ADD COLUMN IF NOT EXISTS `date_out` DATE DEFAULT NULL AFTER `date_in`;

ALTER TABLE `vehicle_logs_archive`
    ADD COLUMN IF NOT EXISTS `date_in` DATE DEFAULT NULL AFTER `recognition_method`,
    ADD COLUMN IF NOT EXISTS `date_out` DATE DEFAULT NULL AFTER `date_in`;

-- Backfill existing records so date_in and date_out are populated
UPDATE `visitor_vehicles`
SET `date_in` = DATE(COALESCE(`time_in`, `date_created`)),
    `date_out` = IF(`time_out` IS NOT NULL, DATE(`time_out`), NULL)
WHERE `date_in` IS NULL;

UPDATE `vehicle_logs`
SET `date_in` = IF(`direction` = 'IN', DATE(`date_created`), `date_in`),
    `date_out` = IF(`direction` = 'OUT', DATE(`date_created`), `date_out`)
WHERE `date_in` IS NULL AND `date_out` IS NULL;

