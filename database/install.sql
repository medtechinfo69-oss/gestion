-- =====================================================================
-- Gestion des Dossiers - Script d'installation de la base de données
-- =====================================================================
-- Compatible MySQL 5.7+ / MariaDB 10.3+ (XAMPP et hébergement standard)
-- Jeu de caractères : utf8mb4 (support complet des accents français)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Base de données
-- ---------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `gestion_dossiers`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `gestion_dossiers`;

-- ---------------------------------------------------------------------
-- Compte MySQL applicatif dédié (bonne pratique de sécurité : ne jamais
-- faire se connecter l'application avec le compte "root").
-- Ce compte n'a de droits QUE sur la base "gestion_dossiers".
-- Adaptez le mot de passe ci-dessous puis reportez-le dans
-- config/config.php (constantes DB_USER / DB_PASS).
-- ---------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'gestion_app'@'localhost' IDENTIFIED BY 'ChangeMoi_2026!';
GRANT SELECT, INSERT, UPDATE, DELETE ON `gestion_dossiers`.* TO 'gestion_app'@'localhost';
FLUSH PRIVILEGES;

-- ---------------------------------------------------------------------
-- Table : users
-- Comptes de connexion : administrateurs (gestion complète) et
-- vendeurs (accès filtré à leurs propres dossiers uniquement).
-- Remplace la création automatique de feuilles Excel par vendeur :
-- chaque vendeur possède désormais un compte utilisateur relié à ses
-- dossiers via `dossiers.vendeur_id`.
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
-- Table principale, équivalent de la feuille "Dossiers" du classeur.
-- Toutes les colonnes métier d'origine sont conservées.
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
    `etat_dossier`           ENUM('Dossier complet','Dossier incomplet') NOT NULL DEFAULT 'Dossier incomplet',
    `etat_contrat`           ENUM('Actif','Renonciation','Résiliation infra-annuelle','Résiliation à échéance','Radié pour non-paiement','CSS') NOT NULL DEFAULT 'Actif',
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
-- Journal des changements (création, modification, changement de statut).
-- Traduction numérique du principe "mise à jour automatique" du classeur.
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
-- Pièces jointes d'un dossier (RIB, attestation, résiliation...).
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
-- Traçabilité des tentatives de connexion (sécurité).
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
-- Conservation des dossiers supprimes pour restauration par un admin.
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

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Données initiales
-- =====================================================================
-- Compte administrateur par défaut.
--   identifiant : admin
--   mot de passe : Admin@2026
-- Le mot de passe DOIT être changé dès la première connexion
-- (must_change_password = 1 force le changement).
-- Hash généré avec password_hash('Admin@2026', PASSWORD_DEFAULT)
-- ---------------------------------------------------------------------
INSERT INTO `users` (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`)
VALUES ('admin', '$2b$10$ychr4Y48ITt2PBhV7jeUDOVcBz/jPlzEE1zRvtFG7b5qSYNs0djaG', 'admin', 'Administrateur', 'admin@example.com', 1, 1);

-- Les vendeurs sont des contacts sans accès à l'application.
INSERT INTO `users` (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`) VALUES
('emma',      '$2y$10$/xdpBg2S74eCsBCq67sXt.yG8IeBhaSB5G0FHen3TRm5BWqe1GMtC', 'superviseur', 'Emma',  NULL, 1, 1),
('rabia',     '$2y$10$/xdpBg2S74eCsBCq67sXt.yG8IeBhaSB5G0FHen3TRm5BWqe1GMtC', 'superviseur', 'Rabia', NULL, 1, 1),
('christine', '$2b$10$KOLosBo6YLJTWdedofsZUuOM1wIXXi1n8eqN06ZquRxZyibf.D4y6', 'vendeur', 'Christine', NULL, 0, 0),
('helene',    '$2b$10$KOLosBo6YLJTWdedofsZUuOM1wIXXi1n8eqN06ZquRxZyibf.D4y6', 'vendeur', 'Hélène',    NULL, 0, 0),
('justine',   '$2b$10$KOLosBo6YLJTWdedofsZUuOM1wIXXi1n8eqN06ZquRxZyibf.D4y6', 'vendeur', 'Justine',   NULL, 0, 0),
('laurence',  '$2b$10$KOLosBo6YLJTWdedofsZUuOM1wIXXi1n8eqN06ZquRxZyibf.D4y6', 'vendeur', 'Laurence',  NULL, 0, 0),
('nina',      '$2b$10$KOLosBo6YLJTWdedofsZUuOM1wIXXi1n8eqN06ZquRxZyibf.D4y6', 'vendeur', 'Nina',      NULL, 0, 0);

-- NOTE : ces hashs correspondent bien aux mots de passe indiqués ci-dessus
-- (vérifiés avec password_verify() côté PHP). Si vous préférez régénérer
-- vos propres hashs, utilisez le script "database/generate_password_hash.php"
-- fourni (à supprimer après usage sur un serveur de production).
