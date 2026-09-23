-- =====================================================================
-- Gestion des Dossiers - INSTALLATION COMPLETE DE LA BASE DE DONNEES
-- =====================================================================
-- Fichier SQL UNIQUE du projet. Il remplace les anciens fichiers separes
-- (install_hosting.sql, chat_tables.sql, dumps de sauvegarde) en creant
-- TOUTES les tables utilisees par le code + les donnees initiales.
--
-- CARACTERISTIQUES
--   * Compatible XAMPP / localhost ET hebergement mutualise (InfinityFree).
--   * Aucune commande CREATE DATABASE / CREATE USER / GRANT / USE :
--     ces commandes sont refusees par l'hebergeur mutualise.
--     => Selectionnez D'ABORD votre base dans phpMyAdmin, PUIS importez.
--   * NON destructif : CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
--     Une table ou une donnee deja presente n'est jamais ecrasee.
--   * Idempotent : le fichier peut etre importe plusieurs fois de suite
--     sans provoquer d'erreur et sans modifier les donnees existantes.
--
-- IMPORT (phpMyAdmin)
--   1. Colonne de gauche : cliquez sur votre base de donnees.
--      Locale     : gestion_dossiers-new
--      Production : if0_42713899_gestion_dossiers
--   2. Onglet "Importer" -> "Choisir un fichier" -> database/install.sql
--   3. "Executer".
--
-- BASE DEJA EN PRODUCTION
--   Ce fichier ne supprime rien : il ajoute seulement les tables
--   manquantes. Pour reparer les cles primaires / AUTO_INCREMENT d'une
--   base existante, lancez : php database/repair_schema.php
--
-- COMPTES CREES (mot de passe a changer a la premiere connexion)
--   admin / Admin@2026                        (administrateur)
--   emma, rabia                               (superviseurs)
--   christine, helene, justine, laurence, nina (vendeurs, inactifs)
--
-- SOMMAIRE
--   SECTION 1 : Coeur metier (utilisateurs + dossiers)
--   SECTION 2 : Espace RH
--   SECTION 3 : Parametres, securite et limitations
--   SECTION 4 : Supervision des sessions superviseurs
--   SECTION 5 : Chat interne et notifications administrateur
--   SECTION 6 : Exports securises par e-mail
--   SECTION 7 : Journalisation et tableau de bord de securite
--   SECTION 8 : Donnees initiales (origines, parametres, comptes)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- SECTION 1 : COEUR METIER
-- =====================================================================

-- ---------------------------------------------------------------------
-- users : comptes de l'application (admin / superviseur / vendeur)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`             VARCHAR(60)  NOT NULL,
    `password_hash`        VARCHAR(255) NOT NULL,
    `role`                 ENUM('admin','superviseur','vendeur') NOT NULL DEFAULT 'vendeur',
    `nom_complet`          VARCHAR(150) NOT NULL,
    `email`                VARCHAR(190) NULL,
    `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
    `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
    `last_password_change` DATETIME     NULL DEFAULT NULL,
    `failed_attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`         DATETIME     NULL DEFAULT NULL,
    `last_login`           DATETIME     NULL DEFAULT NULL,
    `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- dossiers : dossiers d'assurance
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dossiers` (
    `id`                                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vendeur_id`                        INT UNSIGNED NOT NULL,
    `ta_origine`                        VARCHAR(255) NULL,
    `p_prod`                            VARCHAR(100) NULL,
    `date_vente`                        DATE NOT NULL,
    `civilite`                          ENUM('MR','MME','MLLE') NOT NULL,
    `nom`                               VARCHAR(150) NOT NULL,
    `prenom`                            VARCHAR(150) NOT NULL,
    `mail`                              VARCHAR(190) NULL,
    `telfix`                            VARCHAR(30)  NULL,
    `portable`                          VARCHAR(30)  NOT NULL,
    `nombre_personnes`                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `date_naissance_assure`             VARCHAR(255) NULL,
    `age_assure_principal`              VARCHAR(100) NULL,
    `adresse`                           VARCHAR(255) NOT NULL,
    `cp`                                VARCHAR(10)  NOT NULL,
    `ville`                             VARCHAR(150) NOT NULL,
    `type_signature`                    VARCHAR(50)  NOT NULL,
    `ca_mois`                           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `ca_annuel`                         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `date_effet`                        DATE NOT NULL,
    `date_injection`                    DATE NULL,
    `produit`                           VARCHAR(150) NOT NULL,
    `compagnie`                         VARCHAR(100) NOT NULL,
    `courrier`                          TEXT NULL,
    `date_courrier_supervision`         DATE NULL,
    `etat_dossier`                      ENUM('Dossier complet','Dossier incomplet') NOT NULL DEFAULT 'Dossier incomplet',
    `date_dossier_complet`              DATE NULL,
    `etat_contrat`                      ENUM('Actif','Renonciation','Résiliation infra-annuelle','Résiliation à échéance','Radié pour non-paiement','Sans effet qualite','CSS') NOT NULL DEFAULT 'Actif',
    `date_etat_contrat_supervision`     DATE NULL,
    `controle_qualite`                  ENUM('OK','Moyen','KO') NULL DEFAULT NULL,
    `date_controle_qualite_supervision` DATE NULL,
    `date_contrat_non_actif`            DATE NULL,
    `commentaire`                       TEXT NULL,
    `motif_annulation`                  VARCHAR(255) NULL,
    `created_by`                        INT UNSIGNED NOT NULL,
    `updated_by`                        INT UNSIGNED NULL,
    `created_at`                        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dossiers_portable` (`portable`),
    KEY `idx_dossiers_vendeur` (`vendeur_id`),
    KEY `idx_dossiers_etat` (`etat_dossier`),
    KEY `idx_dossiers_date_vente` (`date_vente`),
    KEY `idx_dossiers_compagnie` (`compagnie`),
    KEY `idx_dossiers_ville` (`ville`),
    KEY `idx_dossiers_nom` (`nom`,`prenom`),
    CONSTRAINT `fk_dossiers_vendeur`    FOREIGN KEY (`vendeur_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_dossiers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_dossiers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ---------------------------------------------------------------------
-- dossier_historique : tracabilite des modifications d'un dossier
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dossier_historique` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dossier_id`      INT UNSIGNED NOT NULL,
    `user_id`         INT UNSIGNED NULL,
    `action`          VARCHAR(50) NOT NULL,
    `champ`           VARCHAR(100) NULL,
    `ancienne_valeur` TEXT NULL,
    `nouvelle_valeur` TEXT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hist_dossier` (`dossier_id`),
    KEY `idx_hist_created` (`created_at`),
    CONSTRAINT `fk_hist_dossier` FOREIGN KEY (`dossier_id`) REFERENCES `dossiers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hist_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- dossier_attachments : pieces jointes d'un dossier
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dossier_attachments` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dossier_id`   INT UNSIGNED NOT NULL,
    `nom_original` VARCHAR(255) NOT NULL,
    `nom_fichier`  VARCHAR(255) NOT NULL,
    `type_mime`    VARCHAR(100) NOT NULL,
    `taille`       INT UNSIGNED NOT NULL,
    `uploaded_by`  INT UNSIGNED NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attach_fichier` (`nom_fichier`),
    KEY `idx_attach_dossier` (`dossier_id`),
    CONSTRAINT `fk_attach_dossier` FOREIGN KEY (`dossier_id`)  REFERENCES `dossiers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attach_user`    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- dossier_trash : corbeille (copie complete du dossier supprime)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dossier_trash` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_dossier_id` INT UNSIGNED NOT NULL,
    `dossier_data`       LONGTEXT NOT NULL,
    `attachments_data`   LONGTEXT NOT NULL,
    `historique_data`    LONGTEXT NOT NULL,
    `deleted_by`         INT UNSIGNED NOT NULL,
    `deleted_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_trash_deleted_at` (`deleted_at`),
    KEY `idx_trash_original_id` (`original_dossier_id`),
    CONSTRAINT `fk_trash_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- origines : liste deroulante "origine" des dossiers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `origines` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nom`        VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_origines_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================================
-- SECTION 2 : ESPACE RH
-- =====================================================================

-- ---------------------------------------------------------------------
-- employees : employes (espace RH)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employees` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_code` VARCHAR(100) NOT NULL,
    `full_name`     VARCHAR(150) NOT NULL,
    `pseudo`        VARCHAR(100) NOT NULL DEFAULT '',
    `position`      VARCHAR(150) NOT NULL DEFAULT '',
    `department`    VARCHAR(150) NOT NULL DEFAULT '',
    `contract_type` VARCHAR(100) NOT NULL DEFAULT '',
    `hourly_rate`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `start_date`    DATE NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_employees_code` (`employee_code`),
    KEY `idx_emp_name` (`full_name`),
    KEY `idx_emp_dept` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- salary_records : fiches de paie mensuelles (espace RH)
-- La cle unique uq_salary (employee_id, month, year) est indispensable :
-- elle permet les requetes "INSERT ... ON DUPLICATE KEY UPDATE" utilisees
-- par rh_salary_action.php et rh_salaries.php.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salary_records` (
    `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id`               INT UNSIGNED NOT NULL,
    `month`                     TINYINT UNSIGNED NOT NULL,
    `year`                      SMALLINT UNSIGNED NOT NULL,
    `total_hours`               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `hourly_rate_used`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `calculated_salary`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `normal_worked_days`        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `unjustified_absence_days`  DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `unjustified_absence_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `paid_days`                 DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `paid_hours`                DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `late_count`                INT UNSIGNED NOT NULL DEFAULT 0,
    `heures_sup`                DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `n_depart`                  INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_salary` (`employee_id`, `month`, `year`),
    KEY `idx_month_year` (`month`, `year`),
    CONSTRAINT `fk_salary_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================================
-- SECTION 3 : PARAMETRES, SECURITE ET LIMITATIONS
-- =====================================================================

-- ---------------------------------------------------------------------
-- settings : parametres generaux de l'application (cle / valeur)
-- La cle unique uq_settings_key est indispensable : functions.php utilise
-- "INSERT ... ON DUPLICATE KEY UPDATE".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- password_history : anti-reutilisation des mots de passe
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_history` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `changed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pwdhist_user` (`user_id`, `changed_at`),
    CONSTRAINT `fk_pwdhist_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- rate_limits : anti brute force / limitation de debit
-- La cle unique uq_rate_bucket est indispensable : security.php et
-- includes/SecurityDashboard.php utilisent "INSERT ... ON DUPLICATE KEY".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`       VARCHAR(191) NOT NULL,
    `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
    `window_start` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rate_bucket` (`bucket`),
    KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- login_log : historique des connexions (ip_safe = IP de confiance)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(60) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `ip_safe`    TINYINT(1) NOT NULL DEFAULT 0,
    `success`    TINYINT(1) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_username` (`username`),
    KEY `idx_login_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- user_permissions : droits fins par utilisateur
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_permissions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `permission_key`   VARCHAR(100) NOT NULL,
    `permission_value` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    `granted_by`       INT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_permission` (`user_id`, `permission_key`),
    KEY `idx_perm_user` (`user_id`),
    CONSTRAINT `fk_perm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- security_settings : parametres du module de securite
-- La cle unique uq_secset_key est indispensable : SecurityDashboard.php
-- utilise "INSERT ... ON DUPLICATE KEY UPDATE".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `setting_type`  ENUM('boolean','integer','string','json') DEFAULT 'string',
    `description`   VARCHAR(255) NULL,
    `updated_by`    INT UNSIGNED NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_secset_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================================
-- SECTION 4 : SUPERVISION DES SESSIONS SUPERVISEURS
-- =====================================================================

-- ---------------------------------------------------------------------
-- superviseur_sessions : demande d'acces d'un superviseur (validee par
-- l'administrateur). auth.php / superviseurs.php + actions dediees.
-- Les deux variantes de colonnes (approved_by/denial_reason et
-- decided_by/reason) sont presentes : includes/auth.php les auto-repare.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `superviseur_sessions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `username`      VARCHAR(100) NOT NULL DEFAULT '',
    `ip_address`    VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`    VARCHAR(500) NOT NULL DEFAULT '',
    `status`        ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    `approved_by`   INT UNSIGNED NULL,
    `approved_at`   DATETIME NULL,
    `denial_reason` VARCHAR(255) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `decided_by`    INT UNSIGNED NULL,
    `decided_at`    DATETIME NULL,
    `reason`        VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sup_sessions_status` (`status`, `created_at`),
    KEY `idx_sup_sessions_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- superviseur_approved_ips : adresses IP de confiance d'un superviseur
-- La cle unique uniq_user_ip est indispensable : auth.php et
-- superviseur_session_action.php utilisent "ON DUPLICATE KEY UPDATE".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `superviseur_approved_ips` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             INT UNSIGNED NOT NULL,
    `ip_address`          VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`          VARCHAR(500) NOT NULL DEFAULT '',
    `label`               VARCHAR(150) NULL,
    `approved_by`         INT UNSIGNED NULL,
    `approved_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`        DATETIME NULL,
    `approved_by_user_id` INT UNSIGNED NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_ip` (`user_id`, `ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 5 : CHAT INTERNE ET NOTIFICATIONS ADMINISTRATEUR
-- =====================================================================

-- ---------------------------------------------------------------------
-- chat_messages : messages prives entre utilisateurs (widget de chat)
-- IMPORTANT : "id" doit imperativement etre PRIMARY KEY + AUTO_INCREMENT,
-- sinon includes/chat.php echoue et l'interface affiche
-- "non envoye - reessayez". Voir aussi chat_messages_schema_ensure().
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sender_id`   INT UNSIGNED NOT NULL,
    `receiver_id` INT UNSIGNED NOT NULL,
    `message`     TEXT NOT NULL,
    `is_read`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_chat_pair`    (`sender_id`, `receiver_id`, `created_at`),
    KEY `idx_chat_unread`  (`receiver_id`, `is_read`),
    KEY `idx_chat_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- chat_presence : derniere activite d'un utilisateur (pastille en ligne)
-- "user_id" est PRIMARY KEY (une seule ligne par utilisateur) : c'est ce
-- qui permet le "INSERT ... ON DUPLICATE KEY UPDATE last_seen_at = NOW()".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_presence` (
    `user_id`      INT UNSIGNED NOT NULL,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- admin_notifications : journal des actions a valider par l'admin
-- (badge de notification dans l'en-tete)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_notifications` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id`       INT UNSIGNED NULL,
    `actor_username` VARCHAR(60) NOT NULL DEFAULT '',
    `actor_role`     VARCHAR(20) NOT NULL DEFAULT 'superviseur',
    `action`         VARCHAR(30) NOT NULL,
    `entity`         VARCHAR(30) NOT NULL,
    `entity_id`      VARCHAR(60) NULL,
    `title`          VARCHAR(255) NOT NULL,
    `detail`         TEXT NULL,
    `ip_address`     VARCHAR(45) NULL,
    `is_read`        TINYINT(1) NOT NULL DEFAULT 0,
    `read_by`        INT UNSIGNED NULL,
    `read_at`        DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_unread` (`is_read`, `created_at`),
    KEY `idx_notif_actor`  (`actor_id`, `created_at`),
    KEY `idx_notif_entity` (`entity`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================================
-- SECTION 6 : EXPORTS SECURISES PAR E-MAIL
-- =====================================================================

-- ---------------------------------------------------------------------
-- secure_downloads : fichier (Excel) envoye par e-mail sous forme de
-- lien protege par un code a usage unique.
-- Utilise par actions/dossier_secure_export.php + secure_download.php.
-- IMPORTANT : "id" doit etre PRIMARY KEY + AUTO_INCREMENT.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `secure_downloads` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token`           VARCHAR(64)  NOT NULL,
    `code_hash`       VARCHAR(64)  NOT NULL,
    `filename`        VARCHAR(255) NOT NULL,
    `file_data`       LONGBLOB NOT NULL,
    `expires_at`      DATETIME NOT NULL,
    `max_attempts`    INT UNSIGNED DEFAULT 5,
    `attempts`        INT UNSIGNED DEFAULT 0,
    `last_attempt_at` DATETIME NULL,
    `used_at`         DATETIME NULL,
    `used_ip`         VARCHAR(45) NULL,
    `created_by`      INT UNSIGNED DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_secure_token` (`token`),
    KEY `idx_secure_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 7 : JOURNALISATION ET TABLEAU DE BORD DE SECURITE
-- (utilises par includes/SecurityDashboard.php, includes/security.php,
--  actions/security_alert.php, security_dashboard.php, security_logs.php)
-- =====================================================================

-- ---------------------------------------------------------------------
-- security_alerts : alertes remontees par le navigateur (actions/security_alert.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_alerts` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED DEFAULT 0,
    `user_name`   VARCHAR(150) NOT NULL DEFAULT '',
    `user_email`  VARCHAR(255) NOT NULL DEFAULT '',
    `alert_type`  VARCHAR(50)  NOT NULL,
    `description` TEXT NULL,
    `page_url`    VARCHAR(500) NOT NULL DEFAULT '',
    `ip_address`  VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`  VARCHAR(500) NOT NULL DEFAULT '',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alert_type` (`alert_type`, `created_at`),
    KEY `idx_alert_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- security_audit_log : journal d'audit complet (qui fait quoi, quand)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_audit_log` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`              INT UNSIGNED DEFAULT 0,
    `username`             VARCHAR(100) NOT NULL DEFAULT '',
    `user_role`            ENUM('admin','superviseur','vendeur') NOT NULL DEFAULT 'vendeur',
    `action_type`          ENUM('view','create','update','delete','export','download','login','logout','permission_denied','security_event') NOT NULL,
    `resource_type`        ENUM('dossier','employee','salary','attachment','user','setting','system','export','login') NOT NULL DEFAULT 'system',
    `resource_id`          VARCHAR(100) NULL,
    `resource_description` VARCHAR(500) NULL,
    `ip_address`           VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`           VARCHAR(500) NOT NULL DEFAULT '',
    `request_method`       VARCHAR(10)  NOT NULL DEFAULT '',
    `request_url`          VARCHAR(500) NOT NULL DEFAULT '',
    `query_params`         TEXT NULL,
    `changes_made`         TEXT NULL,
    `access_granted`       TINYINT(1) DEFAULT 1,
    `denial_reason`        VARCHAR(255) NULL,
    `session_id`           VARCHAR(128) NULL,
    `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user`   (`user_id`, `created_at`),
    KEY `idx_audit_action` (`action_type`, `created_at`),
    KEY `idx_audit_res`    (`resource_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ---------------------------------------------------------------------
-- security_events : evenements de securite detectes automatiquement
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_events` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`  ENUM('suspicious_activity','bulk_export','multiple_logins','permission_escalation_attempt','data_access_pattern','rate_limit_exceeded','security_alert') NOT NULL,
    `severity`    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `user_id`     INT UNSIGNED DEFAULT 0,
    `username`    VARCHAR(100) NOT NULL DEFAULT '',
    `description` TEXT NOT NULL,
    `ip_address`  VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`  VARCHAR(500) NOT NULL DEFAULT '',
    `metadata`    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    `resolved`    TINYINT(1) DEFAULT 0,
    `resolved_by` INT UNSIGNED NULL,
    `resolved_at` DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_type` (`event_type`, `created_at`),
    KEY `idx_event_sev`  (`severity`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- security_blocks : blocages (utilisateur / IP / user-agent)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_blocks` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `block_type`   ENUM('user','ip','user_agent') NOT NULL,
    `block_value`  VARCHAR(255) NOT NULL,
    `reason`       VARCHAR(255) NOT NULL,
    `blocked_by`   INT UNSIGNED NULL,
    `expires_at`   DATETIME NULL,
    `is_permanent` TINYINT(1) DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_block_type` (`block_type`, `block_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- data_access_log : tracabilite des acces aux donnees sensibles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `data_access_log` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`            INT UNSIGNED DEFAULT 0,
    `username`           VARCHAR(100) NOT NULL DEFAULT '',
    `accessed_table`     VARCHAR(100) NOT NULL,
    `accessed_record_id` VARCHAR(100) NULL,
    `fields_accessed`    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    `access_reason`      VARCHAR(255) NULL,
    `ip_address`         VARCHAR(45) NOT NULL DEFAULT '',
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_access_user`  (`user_id`, `created_at`),
    KEY `idx_access_table` (`accessed_table`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- login_attempts : tentatives de connexion detaillees (securite)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`       VARCHAR(100) NOT NULL DEFAULT '',
    `ip_address`     VARCHAR(45)  NOT NULL DEFAULT '',
    `user_agent`     VARCHAR(500) NOT NULL DEFAULT '',
    `success`        TINYINT(1) DEFAULT 0,
    `failure_reason` VARCHAR(255) NULL,
    `location_info`  VARCHAR(255) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempt_user` (`username`, `created_at`),
    KEY `idx_attempt_ip`   (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================================
-- SECTION 8 : DONNEES INITIALES
-- INSERT IGNORE => une donnee deja presente n'est jamais ecrasee.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Origines des dossiers (liste deroulante)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `origines` (`nom`) VALUES
('Lead'),
('Fiche perso'),
('MMC 12'),
('MMC 25'),
('FID+2ans');

-- ---------------------------------------------------------------------
-- Parametres generaux de l'application
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name',         'Gestion des Dossiers'),
('max_upload_mb',        '5'),
('password_min_length',  '12'),
('password_max_age_days','90'),
('password_history_count','5');

-- ---------------------------------------------------------------------
-- Parametres du module de securite (security_settings.php + SecurityDashboard)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `security_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('session_timeout',           '7200',  'integer', 'Timeout de session (secondes)'),
('max_login_attempts',        '5',     'integer', 'Tentatives de connexion max'),
('lockout_duration',          '900',   'integer', 'Duree de blocage (secondes)'),
('password_expiry_days',      '90',    'integer', 'Expiration mot de passe (jours)'),
('password_history_count',    '5',     'integer', 'Historique mots de passe'),
('rate_limit_window',         '900',   'integer', 'Fenetre de limitation (secondes)'),
('rate_limit_max_requests',   '100',   'integer', 'Requetes max par fenetre'),
('max_export_rows',           '10000', 'integer', 'Lignes max par export'),
('alert_on_bulk_export',      '1',     'boolean', 'Alerte lors d export en masse'),
('alert_on_multiple_logins',  '1',     'boolean', 'Detecter les connexions multiples'),
('log_security_events',       '1',     'boolean', 'Journaliser tous les evenements');

-- ---------------------------------------------------------------------
-- Comptes initiaux
--   admin                     / Admin@2026        (administrateur)
--   emma, rabia               / Superviseur@2026  (superviseurs)
--   christine, helene, justine, laurence, nina
--                             / Superviseur@2026  (vendeurs, INACTIFS)
-- Tous les comptes doivent changer de mot de passe a la 1re connexion.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `users`
    (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`)
VALUES
    ('admin', '$2y$10$H2hIEP1fxcRX/cxA.8.EJ.Yw.Z9MN8KIOdvi0ke8VQyAMGb0D.hGO', 'admin', 'Administrateur', 'mohamedaymensuuport@gmail.com', 1, 0);

INSERT IGNORE INTO `users`
    (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`)
VALUES
    ('emma',      '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'superviseur', 'Emma',      NULL, 1, 0),
    ('rabia',     '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'superviseur', 'Rabia',     NULL, 1, 0),
    ('christine', '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Christine', NULL, 0, 0),
    ('helene',    '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Hélène',    NULL, 0, 0),
    ('justine',   '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Justine',   NULL, 0, 0),
    ('laurence',  '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Laurence',  NULL, 0, 0),
    ('nina',      '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG', 'vendeur', 'Nina',      NULL, 0, 0);

-- Force le changement de mot de passe a la premiere connexion :
-- tous les comptes sont marques "expires" (il y a plus de 90 jours).
UPDATE `users`
   SET `last_password_change` = DATE_SUB(NOW(), INTERVAL 100 DAY)
 WHERE `last_password_change` IS NULL;

-- Historique initial des mots de passe (anti-reutilisation).
-- Idempotent : n'insere que pour les utilisateurs sans historique.
INSERT IGNORE INTO `password_history` (`user_id`, `password_hash`, `changed_at`)
SELECT u.`id`, u.`password_hash`, COALESCE(u.`last_password_change`, NOW())
  FROM `users` u
 WHERE u.`password_hash` <> ''
   AND u.`id` NOT IN (SELECT `user_id` FROM (SELECT `user_id` FROM `password_history`) AS h);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- FIN DE L'INSTALLATION
-- Verifiez ensuite :
--   1. La connexion MySQL dans config/config.php (ou config.local.php).
--   2. Connexion avec admin / Admin@2026 puis changement de mot de passe.
--   3. Si une table existante est corrompue (cle primaire manquante) :
--      php database/repair_schema.php
-- =====================================================================
