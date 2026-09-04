-- =====================================================================
-- Gestion des Dossiers - Script d'installation de la base de données
-- =====================================================================
-- Compatible MySQL 5.7+ / MariaDB 10.3+ (XAMPP et hébergement standard)
-- Jeu de caractères : utf8mb4 (support complet des accents français)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `gestion_dossiers-new`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gestion_dossiers-new`;

CREATE USER IF NOT EXISTS 'gestion_app'@'localhost' IDENTIFIED BY 'ChangeMoi_2026!';
GRANT ALL PRIVILEGES ON `gestion_dossiers-new`.* TO 'gestion_app'@'localhost';
FLUSH PRIVILEGES;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `dossier_trash`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `dossier_attachments`;
DROP TABLE IF EXISTS `dossier_historique`;
DROP TABLE IF EXISTS `login_log`;
DROP TABLE IF EXISTS `dossiers`;
DROP TABLE IF EXISTS `origines`;
DROP TABLE IF EXISTS `users`;

-- ---------------------------------------------------------------------
-- Table : users
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username`           VARCHAR(60)  NOT NULL,
    `password_hash`      VARCHAR(255) NOT NULL,
    `role`               ENUM('admin','superviseur','vendeur') NOT NULL DEFAULT 'vendeur',
    `nom_complet`        VARCHAR(150) NOT NULL,
    `email`              VARCHAR(190) NULL,
    `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `failed_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`       DATETIME NULL DEFAULT NULL,
    `last_login`         DATETIME NULL DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : dossiers
-- ---------------------------------------------------------------------
CREATE TABLE `dossiers` (
    `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `vendeur_id`             INT UNSIGNED NOT NULL,
    `ta_origine`             VARCHAR(255) NULL,
    `p_prod`                 VARCHAR(100) NULL,
    `date_vente`             DATE NOT NULL,
    `civilite`               ENUM('MR','MME','MLLE') NOT NULL,
    `nom`                    VARCHAR(150) NOT NULL,
    `prenom`                 VARCHAR(150) NOT NULL,
    `mail`                   VARCHAR(190) NULL,
    `telfix`                 VARCHAR(30)  NULL,
    `portable`               VARCHAR(30)  NOT NULL,
    `nombre_personnes`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `date_naissance_assure`  VARCHAR(255) NULL,
    `age_assure_principal`   VARCHAR(100) NULL,
    `adresse`                VARCHAR(255) NOT NULL,
    `cp`                     VARCHAR(10)  NOT NULL,
    `ville`                  VARCHAR(150) NOT NULL,
    `type_signature`         VARCHAR(50)  NOT NULL,
    `ca_mois`                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `ca_annuel`              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `date_effet`             DATE NOT NULL,
    `produit`                VARCHAR(150) NOT NULL,
    `compagnie`              VARCHAR(100) NOT NULL,
    `courrier`               TEXT NULL,
    `date_courrier_supervision` DATE NULL,
    `etat_dossier`           ENUM('Dossier complet','Dossier incomplet') NOT NULL DEFAULT 'Dossier incomplet',
    `date_dossier_complet`   DATE NULL,
    `etat_contrat`           ENUM('Actif','Renonciation','Résiliation infra-annuelle','Résiliation à échéance','Radié pour non-paiement','Sans effet qualite','CSS') NOT NULL DEFAULT 'Actif',
    `date_etat_contrat_supervision` DATE NULL,
    `controle_qualite`       ENUM('OK','Moyen','KO') NULL DEFAULT NULL,
    `date_controle_qualite_supervision` DATE NULL,
    `date_contrat_non_actif` DATE NULL,
    `commentaire`            TEXT NULL,
    `motif_annulation`       VARCHAR(255) NULL,
    `created_by`             INT UNSIGNED NOT NULL,
    `updated_by`             INT UNSIGNED NULL,
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_dossiers_portable` (`portable`),
    KEY `idx_dossiers_vendeur` (`vendeur_id`),
    KEY `idx_dossiers_etat` (`etat_dossier`),
    KEY `idx_dossiers_date_vente` (`date_vente`),
    KEY `idx_dossiers_compagnie` (`compagnie`),
    KEY `idx_dossiers_ville` (`ville`),
    KEY `idx_dossiers_nom` (`nom`,`prenom`),
    CONSTRAINT `fk_dossiers_vendeur` FOREIGN KEY (`vendeur_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_dossiers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_dossiers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : dossier_historique
-- ---------------------------------------------------------------------
CREATE TABLE `dossier_historique` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `dossier_id`      INT UNSIGNED NOT NULL,
    `user_id`         INT UNSIGNED NULL,
    `action`          VARCHAR(50) NOT NULL,
    `champ`           VARCHAR(100) NULL,
    `ancienne_valeur` TEXT NULL,
    `nouvelle_valeur` TEXT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_hist_dossier` (`dossier_id`),
    CONSTRAINT `fk_hist_dossier` FOREIGN KEY (`dossier_id`) REFERENCES `dossiers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hist_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : dossier_attachments
-- ---------------------------------------------------------------------
CREATE TABLE `dossier_attachments` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `dossier_id`      INT UNSIGNED NOT NULL,
    `nom_original`    VARCHAR(255) NOT NULL,
    `nom_fichier`     VARCHAR(255) NOT NULL,
    `type_mime`       VARCHAR(100) NOT NULL,
    `taille`          INT UNSIGNED NOT NULL,
    `uploaded_by`     INT UNSIGNED NOT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_attach_fichier` (`nom_fichier`),
    KEY `idx_attach_dossier` (`dossier_id`),
    CONSTRAINT `fk_attach_dossier` FOREIGN KEY (`dossier_id`) REFERENCES `dossiers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attach_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : login_log
-- ---------------------------------------------------------------------
CREATE TABLE `login_log` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username`   VARCHAR(60) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `success`    TINYINT(1) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_login_username` (`username`),
    KEY `idx_login_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : dossier_trash
-- ---------------------------------------------------------------------
CREATE TABLE `dossier_trash` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `original_dossier_id` INT UNSIGNED NOT NULL,
    `dossier_data` LONGTEXT NOT NULL,
    `attachments_data` LONGTEXT NOT NULL,
    `historique_data` LONGTEXT NOT NULL,
    `deleted_by` INT UNSIGNED NOT NULL,
    `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_trash_deleted_at` (`deleted_at`),
    KEY `idx_trash_original_id` (`original_dossier_id`),
    CONSTRAINT `fk_trash_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : origines
-- ---------------------------------------------------------------------
CREATE TABLE `origines` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nom`        VARCHAR(255) NOT NULL UNIQUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `origines` (`nom`) VALUES ('Lead'), ('Fiche perso'), ('MMC 12'), ('MMC 25'), ('FID+2ans');

-- ---------------------------------------------------------------------
-- Table : employees (Espace RH)
-- ---------------------------------------------------------------------
CREATE TABLE `employees` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_code`  VARCHAR(100) NOT NULL UNIQUE,
    `full_name`      VARCHAR(150) NOT NULL,
    `pseudo`         VARCHAR(100) NOT NULL DEFAULT '',
    `position`       VARCHAR(150) NOT NULL DEFAULT '',
    `department`     VARCHAR(150) NOT NULL DEFAULT '',
    `contract_type`  VARCHAR(100) NOT NULL DEFAULT '',
    `hourly_rate`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status`         ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `start_date`     DATE NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_emp_name` (`full_name`),
    KEY `idx_emp_dept` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : salary_records (Espace RH)
-- ---------------------------------------------------------------------
CREATE TABLE `salary_records` (
    `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id`       INT UNSIGNED NOT NULL,
    `month`             TINYINT UNSIGNED NOT NULL,
    `year`              SMALLINT UNSIGNED NOT NULL,
    `total_hours`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `hourly_rate_used`  DECIMAL(12,2) NOT NULL DEFAULT 0,
    `calculated_salary` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `normal_worked_days` DECIMAL(8,2) NOT NULL DEFAULT 0,
    `unjustified_absence_days` DECIMAL(8,2) NOT NULL DEFAULT 0,
    `unjustified_absence_hours` DECIMAL(8,2) NOT NULL DEFAULT 0,
    `paid_days` DECIMAL(8,2) NOT NULL DEFAULT 0,
    `paid_hours` DECIMAL(8,2) NOT NULL DEFAULT 0,
    `late_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_salary` (`employee_id`, `month`, `year`),
    KEY `idx_month_year` (`month`, `year`),
    CONSTRAINT `fk_salary_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : settings (Espace RH)
-- ---------------------------------------------------------------------
CREATE TABLE `settings` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key`  VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NOT NULL,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'Gestion des Dossiers'),
('max_upload_mb', '5');

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Données initiales
-- ---------------------------------------------------------------------
INSERT INTO `users` (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`)
VALUES ('admin', '$2y$10$H2hIEP1fxcRX/cxA.8.EJ.Yw.Z9MN8KIOdvi0ke8VQyAMGb0D.hGO', 'admin', 'Administrateur', 'admin@example.com', 1, 0);

INSERT INTO `users` (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`) VALUES
('emma',      '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'superviseur', 'Emma',  NULL, 1, 0),
('rabia',     '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'superviseur', 'Rabia', NULL, 1, 0),
('christine', '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Christine', NULL, 0, 0),
('helene',    '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Hélène',    NULL, 0, 0),
('justine',   '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Justine',   NULL, 0, 0),
('laurence',  '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Laurence',  NULL, 0, 0),
('nina',      '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Nina',      NULL, 0, 0);
