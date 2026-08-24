-- =====================================================================
-- Reinitialisation des mots de passe des comptes pour l'hébergement
-- Exécuter ce fichier dans phpMyAdmin sur votre hébergeur (InfinityFree)
-- =====================================================================

UPDATE `users` SET 
  `password_hash` = '$2y$10$S8mFXMU6SdqjiHddKHHT7enaDcAyGobfoJ5Ih.oTK2mA.c8TAdKta',
  `must_change_password` = 0,
  `failed_attempts` = 0,
  `locked_until` = NULL
WHERE `username` = 'admin';

UPDATE `users` SET 
  `password_hash` = '$2y$10$de.8ocal3dUgOqY1hzAq9ef4ejW3YmHAmjEMWURQ7Kk/ecPUhCSgG',
  `must_change_password` = 0,
  `failed_attempts` = 0,
  `locked_until` = NULL
WHERE `username` IN ('emma', 'rabia');

