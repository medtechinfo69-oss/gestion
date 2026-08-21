-- Migration des droits : vendeurs sans accès, superviseurs en lecture globale.
USE `gestion_dossiers`;

ALTER TABLE `users`
    MODIFY `role` ENUM('admin','superviseur','vendeur') NOT NULL DEFAULT 'vendeur';

-- Les anciens comptes vendeur deviennent des contacts sans accès à l'application.
UPDATE `users`
SET `is_active` = 0, `must_change_password` = 0
WHERE `role` = 'vendeur';

INSERT INTO `users`
    (`username`, `password_hash`, `role`, `nom_complet`, `email`, `is_active`, `must_change_password`)
VALUES
    ('emma', '$2y$10$/xdpBg2S74eCsBCq67sXt.yG8IeBhaSB5G0FHen3TRm5BWqe1GMtC', 'superviseur', 'Emma', NULL, 1, 1),
    ('rabia', '$2y$10$/xdpBg2S74eCsBCq67sXt.yG8IeBhaSB5G0FHen3TRm5BWqe1GMtC', 'superviseur', 'Rabia', NULL, 1, 1)
ON DUPLICATE KEY UPDATE
    `role` = VALUES(`role`),
    `nom_complet` = VALUES(`nom_complet`),
    `is_active` = 1,
    `must_change_password` = 1;

-- Mot de passe initial des deux superviseurs : Superviseur@2026.
-- Il doit être changé à la première connexion.
