-- Run once in the hosting database using phpMyAdmin.
-- Existing employees and salary records are preserved.

ALTER TABLE `employees`
    ADD COLUMN `pseudo` VARCHAR(100) NOT NULL DEFAULT '' AFTER `full_name`;

ALTER TABLE `salary_records`
    ADD COLUMN `normal_worked_days` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `calculated_salary`,
    ADD COLUMN `unjustified_absence_days` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `normal_worked_days`,
    ADD COLUMN `unjustified_absence_hours` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `unjustified_absence_days`,
    ADD COLUMN `paid_days` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `unjustified_absence_hours`,
    ADD COLUMN `paid_hours` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `paid_days`,
    ADD COLUMN `late_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `paid_hours`;