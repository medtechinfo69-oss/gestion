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
-- Sélectionnez la base cible dans phpMyAdmin avant l'import.

-- ---------------------------------------------------------------------
-- Le compte MySQL doit être créé dans le panneau de l'hébergeur ou dans
-- l'environnement local, puis renseigné dans config/config.php.
-- ---------------------------------------------------------------------

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
VALUES ('admin', '$2y$10$S8mFXMU6SdqjiHddKHHT7enaDcAyGobfoJ5Ih.oTK2mA.c8TAdKta', 'admin', 'Administrateur', 'admin@example.com', 1, 1);

-- Les vendeurs sont des contacts sans accès à l'application.
INSERT INTO `users` (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`) VALUES
('emma',      '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG', 'superviseur', 'Emma',  NULL, 1, 1),
('rabia',     '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG', 'superviseur', 'Rabia', NULL, 1, 1),
('christine', '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG', 'vendeur', 'Christine', NULL, 0, 0),
('helene',    '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG', 'vendeur', 'Hélène',    NULL, 0, 0),
('justine',   '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG', 'vendeur', 'Justine',   NULL, 0, 0),
('laurence',  '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG', 'vendeur', 'Laurence',  NULL, 0, 0),
('nina',      '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG', 'vendeur', 'Nina',      NULL, 0, 0);

-- =====================================================================
-- Données de démonstration
-- =====================================================================
-- Les IDs correspondent à l'ordre des INSERT ci-dessus :
-- admin=1, emma=2, rabia=3, christine=4, helene=5, justine=6,
-- laurence=7, nina=8.
INSERT INTO `dossiers`
(`vendeur_id`, `ta_origine`, `p_prod`, `date_vente`, `civilite`, `nom`, `prenom`, `mail`, `telfix`, `portable`,
 `nombre_personnes`, `date_naissance_assure`, `age_assure_principal`, `adresse`, `cp`, `ville`, `type_signature`,
 `ca_mois`, `ca_annuel`, `date_effet`, `produit`, `compagnie`, `etat_dossier`, `commentaire`, `motif_annulation`,
 `created_by`)
VALUES
(8, 'LEAD', '5 ASSUR', '2026-01-05', 'MME', 'MARTIN', 'SYLVIE', 'sylvie.martin@example.com', NULL, '0601020304', 2, '03/09/1958', NULL, '12 rue des Lilas', '75012', 'Paris', 'SMS', 207.34, 2488.08, '2026-02-01', 'SILVER 2', 'KIASSURE', 'Dossier complet', 'Dossier complet reçu par courrier le 20/01/2026', NULL, 1),
(8, 'TRANSFERT', '5 ASSUR', '2026-01-06', 'MR', 'DUBOIS', 'MARC', 'marc.dubois@example.com', '0145123456', '0601020305', 1, '26/04/1963', NULL, '5 avenue Victor Hugo', '69003', 'Lyon', 'SMS', 66.83, 801.96, '2026-04-01', 'SILVER 1', 'KIASSURE', 'Dossier complet', 'Dossier complet reçu par courrier le 16/02/2026', NULL, 1),
(8, 'LEAD', '5 ASSUR', '2026-01-14', 'MR', 'LAGARDE', 'ANDRE', 'andre.lagarde@example.com', NULL, '0601020306', 2, '15/12/1947 et 18/07/1950', NULL, '4 rue des Vergers', '43320', 'Sanssac-l’Eglise', 'SMS', 235.79, 2829.48, '2026-03-01', 'SILVER 3', 'KIASSURE', 'Dossier incomplet', 'Manque résiliation, RIB', NULL, 1),
(4, '5ASSUR', '5 ASSUR', '2026-01-15', 'MR', 'VILLONI', 'ALAIN', 'alain.villoni@example.com', NULL, '0601020307', 2, '31/07/1962', '64 ans', '56 allée des Peupliers', '84460', 'Cheval-Blanc', 'MAIL+SMS', 158.12, 1897.44, '2027-02-01', 'FMA ESSENTIEL 2', 'FMA', 'Dossier incomplet', 'Attente pièces complémentaires', NULL, 1),
(5, 'MMC 25', '5 ASSUR', '2025-05-27', 'MR', 'VOLLOT', 'ALAIN', 'alain.vollot@example.com', NULL, '0601020308', 1, '13/02/1953', '72 ans', '4 chemin de Lavau', '21340', 'Molinot', 'SMS', 67.05, 804.60, '2026-01-01', 'VITALIA HOSPI COMPLETE', 'FMA', 'Dossier incomplet', 'Assuré indécis', NULL, 1),
(5, 'PARRAINAGE', '5 ASSUR', '2025-03-07', 'MR', 'FAURE', 'JEAN MARIE', 'jm.faure@example.com', NULL, '0601020309', 1, '11/09/1944', '81 ans', '31 rue de la Libération', '25420', 'Dampierre-sur-le-Doubs', 'SMS', 127.01, 1524.12, '2025-05-01', 'INSTASSUR 2', 'KIASSURE', 'Dossier incomplet', 'Contrat résilié', 'Radiation pour non paiement', 1),
(8, 'MMC 12', '5 ASSUR', '2025-06-10', 'MME', 'YAOUE', 'NERGINIE', NULL, NULL, '0601020310', 1, '04/05/1978', NULL, '24 rue Eugène Boudin', '57000', 'Metz', 'SMS', 64.42, 773.04, '2025-07-01', 'ESSENTIEL 2', 'FMA', 'Dossier incomplet', 'Radiation pour non paiement', 'Radiation pour non paiement', 1),
(7, 'FICHE PERSO', '5 ASSUR', '2025-09-15', 'MR', 'FENET', 'AIME', 'aime.fenet@example.com', NULL, '0601020311', 2, '02/07/1968', NULL, '55 rue de Voile Latine', '30240', 'Le Grau-du-Roi', 'SMS', 106.77, 1281.24, '2026-02-05', 'SILVER 1', 'KIASSURE', 'Dossier incomplet', 'En attente devoir de conseil', NULL, 1);

UPDATE `dossiers`
SET `etat_contrat` = 'Radié pour non-paiement'
WHERE `portable` IN ('0601020309', '0601020310');
