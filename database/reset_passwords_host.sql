-- =====================================================================
-- Reinitialisation des mots de passe des comptes pour l'hébergement
-- Exécuter ce fichier dans phpMyAdmin sur votre hébergeur (InfinityFree)
-- =====================================================================

UPDATE `users` SET 
  `password_hash` = '$2y$10$H2hIEP1fxcRX/cxA.8.EJ.Yw.Z9MN8KIOdvi0ke8VQyAMGb0D.hGO',
  `must_change_password` = 0,
  `failed_attempts` = 0,
  `locked_until` = NULL
WHERE `username` = 'admin';

UPDATE `users` SET 
  `password_hash` = '$2y$10$xx4/00jinAg5ZEa30M1R5us7.cfp0SxM/O4YQzmRzy2zvQ9pgC8GG',
  `must_change_password` = 0,
  `failed_attempts` = 0,
  `locked_until` = NULL
WHERE `username` IN ('emma', 'rabia');
