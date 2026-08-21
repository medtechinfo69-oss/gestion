-- Ajout des colonnes Courrier et Etat du contrat.
USE `gestion_dossiers`;
SET NAMES utf8mb4;

ALTER TABLE `dossiers`
    ADD COLUMN `courrier` TEXT NULL AFTER `compagnie`,
    ADD COLUMN `etat_contrat` ENUM('Actif','Renonciation','Résiliation infra-annuelle','Résiliation à échéance','Radié pour non-paiement','CSS') NOT NULL DEFAULT 'Actif' AFTER `etat_dossier`,
    MODIFY `etat_dossier` ENUM('Dossier complet','Dossier incomplet','Non complet','Annuler') NOT NULL DEFAULT 'Dossier incomplet';

-- Conserve les dossiers existants : l'ancien statut manuel devient le statut derive.
UPDATE `dossiers` SET `etat_dossier` = 'Dossier incomplet' WHERE `etat_dossier` = 'Non complet';
UPDATE `dossiers`
SET `etat_dossier` = 'Dossier incomplet', `etat_contrat` = 'Radié pour non-paiement'
WHERE `etat_dossier` = 'Annuler';

ALTER TABLE `dossiers`
    MODIFY `etat_dossier` ENUM('Dossier complet','Dossier incomplet') NOT NULL DEFAULT 'Dossier incomplet';

-- Les anciennes lignes n'ont pas de cases courrier disponibles.
UPDATE `dossiers` SET `courrier` = '[]' WHERE `courrier` IS NULL;
