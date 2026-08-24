-- Corbeille des dossiers supprimes.
-- A executer une seule fois sur une base existante.
CREATE TABLE IF NOT EXISTS `dossier_trash` (
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
