-- =====================================================================
-- Données de démonstration (OPTIONNEL)
-- À importer après install.sql si vous voulez un jeu de données de test.
-- Suppose que les comptes vendeurs 2 à 6 (christine, helene, justine,
-- laurence, nina) existent déjà avec ces mêmes identifiants (id 2-6).
-- =====================================================================
USE `gestion_dossiers`;

INSERT INTO `dossiers`
(`vendeur_id`, `ta_origine`, `p_prod`, `date_vente`, `civilite`, `nom`, `prenom`, `mail`, `telfix`, `portable`,
 `nombre_personnes`, `date_naissance_assure`, `age_assure_principal`, `adresse`, `cp`, `ville`, `type_signature`,
 `ca_mois`, `ca_annuel`, `date_effet`, `produit`, `compagnie`, `etat_dossier`, `commentaire`, `motif_annulation`,
 `created_by`)
VALUES
(6, 'LEAD', '5 ASSUR', '2026-01-05', 'MME', 'MARTIN', 'SYLVIE', 'sylvie.martin@example.com', NULL, '0601020304',
 2, '03/09/1958', NULL, '12 rue des Lilas', '75012', 'Paris', 'SMS',
 207.34, 2488.08, '2026-02-01', 'SILVER 2', 'KIASSURE', 'Dossier complet', 'Dossier complet reçu par courrier le 20/01/2026', NULL, 1),

(6, 'TRANSFERT', '5 ASSUR', '2026-01-06', 'MR', 'DUBOIS', 'MARC', 'marc.dubois@example.com', '0145123456', '0601020305',
 1, '26/04/1963', NULL, '5 avenue Victor Hugo', '69003', 'Lyon', 'SMS',
 66.83, 801.96, '2026-04-01', 'SILVER 1', 'KIASSURE', 'Dossier complet', 'Dossier complet reçu par courrier le 16/02/2026', NULL, 1),

(6, 'LEAD', '5 ASSUR', '2026-01-14', 'MR', 'LAGARDE', 'ANDRE', 'andre.lagarde@example.com', NULL, '0601020306',
 2, '15/12/1947 et 18/07/1950', NULL, '4 rue des Vergers', '43320', 'Sanssac-l’Église', 'SMS',
 235.79, 2829.48, '2026-03-01', 'SILVER 3', 'KIASSURE', 'Dossier incomplet', 'Manque résiliation, RIB', NULL, 1),

(3, '5ASSUR', '5 ASSUR', '2026-01-15', 'MR', 'VILLONI', 'ALAIN', 'alain.villoni@example.com', NULL, '0601020307',
 2, '31/07/1962', '64 ans', '56 allée des Peupliers', '84460', 'Cheval-Blanc', 'MAIL+SMS',
 158.12, 1897.44, '2027-02-01', 'FMA ESSENTIEL 2', 'FMA', 'Dossier incomplet', 'Attente pièces complémentaires', NULL, 1),

(4, 'MMC 25', '5 ASSUR', '2025-05-27', 'MR', 'VOLLOT', 'ALAIN', 'alain.vollot@example.com', NULL, '0601020308',
 1, '13/02/1953', '72 ans', '4 chemin de Lavau', '21340', 'Molinot', 'SMS',
 67.05, 804.60, '2026-01-01', 'VITALIA HOSPI COMPLETE', 'FMA', 'Dossier incomplet', 'Assuré indécis', NULL, 1),

(4, 'PARRAINAGE', '5 ASSUR', '2025-03-07', 'MR', 'FAURE', 'JEAN MARIE', 'jm.faure@example.com', NULL, '0601020309',
 1, '11/09/1944', '81 ans', '31 rue de la Libération', '25420', 'Dampierre-sur-le-Doubs', 'SMS',
 127.01, 1524.12, '2025-05-01', 'INSTASSUR 2', 'KIASSURE', 'Dossier incomplet', 'Contrat résilié', 'Radiation pour non paiement', 1),

(6, 'MMC 12', '5 ASSUR', '2025-06-10', 'MME', 'YAOUE', 'NERGINIE', NULL, NULL, '0601020310',
 1, '04/05/1978', NULL, '24 rue Eugène Boudin', '57000', 'Metz', 'SMS',
 64.42, 773.04, '2025-07-01', 'ESSENTIEL 2', 'FMA', 'Dossier incomplet', 'Radiation pour non paiement', 'Radiation pour non paiement', 1),

(5, 'FICHE PERSO', '5 ASSUR', '2025-09-15', 'MR', 'FENET', 'AIME', 'aime.fenet@example.com', NULL, '0601020311',
 2, '02/07/1968', NULL, '55 rue de Voile Latine', '30240', 'Le Grau-du-Roi', 'SMS',
 106.77, 1281.24, '2026-02-05', 'SILVER 1', 'KIASSURE', 'Dossier incomplet', 'En attente devoir de conseil', NULL, 1);

UPDATE `dossiers`
SET `etat_contrat` = 'Radié pour non-paiement'
WHERE `portable` IN ('0601020309', '0601020310');
