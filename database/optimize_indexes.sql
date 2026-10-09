-- =====================================================================
-- OPTIMISATION / INDEX MANQUANTS
-- ---------------------------------------------------------------------
-- Constat : database/install.sql declare bien ces index, mais une base
-- installee avant leur ajout peut ne pas les avoir. Chaque page qui liste
-- les dossiers ou affiche l'historique fait alors un balayage complet de
-- table, de plus en plus lent a mesure que les donnees grossissent.
--
-- IMPORTANT — execution depuis PHP (page /tz_migration_admin.php) :
--   Ce fichier est volontairement ecrit avec de simples ALTER TABLE, SANS
--   `SET @var := (SELECT ...)`. Cette forme fonctionne dans le client mysql
--   mais ECHOUE via PDO : « Cannot execute queries while other unbuffered
--   queries are active » (SQLSTATE 2014). Les deux facons d'executer ce
--   fichier doivent donc fonctionner, d'ou le choix de la forme simple.
--
--   Chaque ALTER est idempotent : relancer ne casse rien, MySQL renvoie
--   simplement « Duplicate key name », que l'appelant tolere comme succes.
--
-- Application :
--   - navigateur : https://VOTRE-DOMAINE/tz_migration_admin.php
--   - ligne de commande :
--       mysql -u <user> -p <base> < database/optimize_indexes.sql
-- =====================================================================

-- Historique d'un dossier : filtre par dossier_id ET tri par created_at.
-- C'est LA requete la plus frequente de l'application (fiche dossier).
-- Sans cet index : balayage des ~2 700 lignes + tri a chaque ouverture.
ALTER TABLE `dossier_historique` ADD INDEX `idx_hist_dossier_date` (`dossier_id`, `created_at`);

-- Liste des dossiers d'un vendeur : le vendeur connecte filtre toujours sur
-- vendeur_id, ce qui evite de scanner toute la table des dossiers.
ALTER TABLE `dossiers` ADD INDEX `idx_dossiers_vendeur` (`vendeur_id`);

-- Page « Dossiers annules » : filtre etat_contrat + tri par date de vente.
ALTER TABLE `dossiers` ADD INDEX `idx_dossiers_etat_vente` (`etat_contrat`, `date_vente`);

-- Filtre « Compagnie » : SELECT DISTINCT compagnie ORDER BY compagnie.
-- Sans index, MySQL construit une table temporaire puis trie toutes les lignes.
ALTER TABLE `dossiers` ADD INDEX `idx_dossiers_compagnie` (`compagnie`);

-- Pieces jointes d'un dossier (carte « Pieces jointes » de la fiche).
ALTER TABLE `dossier_attachments` ADD INDEX `idx_attach_dossier` (`dossier_id`);

-- Menu « Vendeurs » : WHERE role='vendeur' ORDER BY nom_complet.
ALTER TABLE `users` ADD INDEX `idx_users_role_nom` (`role`, `nom_complet`);

-- Journal de securite : ecran « Surveillance » filtre sur les 24 dernieres heures.
ALTER TABLE `security_events` ADD INDEX `idx_sec_events_date` (`created_at`);

-- Page notifications.php : liste triee par created_at DESC, id DESC.
-- Cette table est INSERT-ONLY : sans cet index elle grossit indefiniment et
-- chaque page paie un balayage complet + tri.
ALTER TABLE `admin_notifications` ADD INDEX `idx_notif_created` (`created_at`, `id`);

-- Fin du fichier : 8 index au maximum, aucun risque pour les donnees.