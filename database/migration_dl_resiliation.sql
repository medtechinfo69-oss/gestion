-- Migration : DL résiliation manuelle (Hausse tarifaire / Autres)
-- À appliquer UNE fois sur la base d'hébergement via phpMyAdmin > SQL.
-- Si vous obtenez « Duplicate column name 'dl_resiliation' », c'est que la
-- colonne existe déjà : rien à faire.
-- (Alternative recommandée : menu admin → « Réparation BDD », qui ajoute
--  la colonne automatiquement de façon idempotente.)
ALTER TABLE `dossiers` ADD COLUMN `dl_resiliation` DATE NULL AFTER `type_resiliation`;
