-- =====================================================================
-- Gestion des Dossiers - Mise à niveau sécurité v2
-- =====================================================================
-- À exécuter UNE SEULE FOIS après installation, dans phpMyAdmin.
-- Ajoute :
--   * users.last_password_change (expiration des mots de passe)
--   * password_history          (anti-réutilisation des mots de passe)
--   * rate_limits               (limitation de débit / anti brute force)
--   * login_log.ip_safe         (détection d'adresses IP connues)
-- Compatible MySQL 5.7+ / MariaDB 10.3+ (XAMPP et hébergement standard)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Nouvelle colonne : date du dernier changement de mot de passe
-- ---------------------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN `last_password_change` DATETIME NULL DEFAULT NULL AFTER `must_change_password`;

-- Les comptes existants doivent changer leur mot de passe si celui-ci
-- date d'avant cette mise à niveau (expiration à 90 jours).
UPDATE `users`
   SET `last_password_change` = DATE_SUB(NOW(), INTERVAL 100 DAY)
 WHERE `last_password_change` IS NULL;

-- ---------------------------------------------------------------------
-- 2. Table : historique des mots de passe (évite la réutilisation)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_history` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `password_hash`   VARCHAR(255) NOT NULL,
    `changed_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pwdhist_user` (`user_id`, `changed_at`),
    CONSTRAINT `fk_pwdhist_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique initial : le mot de passe actuel de chaque compte
INSERT INTO `password_history` (`user_id`, `password_hash`, `changed_at`)
SELECT `id`, `password_hash`, `last_password_change`
  FROM `users`
 WHERE `password_hash` IS NOT NULL AND `password_hash` <> '';

-- ---------------------------------------------------------------------
-- 3. Table : limitation de débit (anti brute force / bourrage d'identifiants)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bucket`       VARCHAR(191) NOT NULL,
    `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
    `window_start` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_rate_bucket` (`bucket`),
    KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Colonne IP sûr dans login_log (détection de nouvelles adresses)
-- ---------------------------------------------------------------------
ALTER TABLE `login_log`
    ADD COLUMN `ip_safe` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ip_address`;

-- ---------------------------------------------------------------------
-- 5. Mémo de configuration : expirations actives
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('password_min_length', '12'),
('password_max_age_days', '90'),
('password_history_count', '5')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);