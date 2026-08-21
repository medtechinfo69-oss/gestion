<<<<<<< HEAD
# gestion
=======
# Gestion des Dossiers

Application web de gestion des dossiers d'assurance, développée pour remplacer
le classeur Excel `gestion_dossiers_final.xlsm`. Reprend l'intégralité des
règles métier identifiées dans le classeur d'origine :

- Fiche dossier complète (vendeur, assuré, coordonnées, contrat, commentaire).
- **Numéro de portable unique** entre tous les dossiers (règle de validation
  Excel `COUNTIF(...)=1` reproduite côté serveur ET en base via une contrainte
  `UNIQUE`).
- **Courrier** avec les cases `Résiliation`, `RIB`, `Devoir de conseil` et
  `Consentement`. Les quatre cases donnent automatiquement `Dossier complet`,
  sinon `Dossier incomplet`.
- **État du contrat** limité à `Actif`, `Renonciation`, `Résiliation infra-annuelle`,
  `Résiliation à échéance`, `Radié pour non-paiement` et `CSS`. Les contrats
  non actifs apparaissent dans `Dossiers annulés`.
- **CA annuel = CA mensuel × 12**, calculé automatiquement (modifiable si besoin).
- Bloc **statistiques** : total du CA annuel hors dossiers annulés, nombre de
  dossiers complets / non complets / annulés — global et par vendeur.
- Vue dédiée **« Dossiers annulés »**, équivalent de l'onglet `Annulation`.
- Un vendeur est un contact sans accès, enregistré avec son nom et son e-mail.
- Les superviseurs voient tous les dossiers et peuvent uniquement modifier
  `Courrier` et `Commentaire dossier`. `État du dossier` est calculé automatiquement.

Stack : **HTML / CSS / JavaScript natif / PHP natif (aucun framework) / MySQL**.

---

## 1. Structure du projet

```
gestion-dossiers/
├── actions/            Scripts de traitement des formulaires (POST uniquement)
├── assets/              CSS et JavaScript
├── config/               Configuration (protégée, non accessible par le web)
├── database/            Scripts SQL d'installation
├── includes/             Fonctions PHP partagées (protégées)
├── *.php                 Pages de l'application
└── .htaccess             Règles de sécurité Apache

---
## 2. Installation locale avec XAMPP

- Extensions PHP requises (activées par défaut sur XAMPP) : `pdo_mysql`,
Copiez le dossier `gestion-dossiers` complet dans le répertoire web de XAMPP :

- Windows : `C:\xampp\htdocs\gestion-dossiers`
- macOS : `/Applications/XAMPP/htdocs/gestion-dossiers`

### Étape 2 — Créer la base de données
1. Démarrez **Apache** et **MySQL** depuis le panneau de contrôle XAMPP.
2. Ouvrez **phpMyAdmin** (`http://localhost/phpmyadmin`).
3. Onglet **SQL**, collez le contenu du fichier `database/install.sql`, puis
   exécutez. Cela crée :
   - la base `gestion_dossiers` ;
   - toutes les tables ;
   - un compte MySQL applicatif dédié `gestion_app` (mot de passe
     `ChangeMoi_2026!`) avec des droits limités à cette seule base ;
   - un compte administrateur, Emma et Rabia comme superviseurs, et des
     vendeurs de démonstration sans accès.
4. *(Optionnel)* Pour tester avec des données d'exemple, exécutez ensuite le
   contenu de `database/seed_demo_data.sql`.

Pour une base déjà installée, exécutez aussi `database/migrate_superviseurs_vendeurs.sql`
dans phpMyAdmin. Cette migration désactive les anciens comptes vendeurs et crée Emma
et Rabia comme superviseurs.

> **Remarque XAMPP** : si vous préférez utiliser directement le compte
> `root` (mot de passe vide) pour aller plus vite en test local, ouvrez
> `config/config.php` et remplacez `DB_USER`/`DB_PASS` par `'root'` et `''`.
> Pour un usage au-delà d'un simple test, gardez le compte dédié `gestion_app`
> (bonne pratique de sécurité : l'application ne doit jamais se connecter
> avec un compte administrateur de la base).

### Étape 3 — Vérifier la configuration
Ouvrez `config/config.php` et vérifiez :

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_dossiers');
define('DB_USER', 'gestion_app');
define('DB_PASS', 'ChangeMoi_2026!');
define('APP_URL', 'http://localhost/gestion-dossiers');
define('APP_ENV', 'development'); // repassez à 'production' avant mise en ligne
```

Adaptez `APP_URL` si vous avez copié le dossier sous un autre nom.

### Étape 4 — Droits d'écriture
Le dossier `uploads/dossiers/` doit être accessible en écriture par le
serveur web (pièces jointes) :

- Windows : généralement déjà correct par défaut avec XAMPP.
- macOS/Linux : `chmod -R 755 uploads/ logs/`

### Étape 5 — Se connecter
Rendez-vous sur `http://localhost/gestion-dossiers/`.

**Comptes de démonstration** (mot de passe à changer à la première connexion) :

| Rôle          | Identifiant | Mot de passe   |
|---------------|-------------|----------------|
| Administrateur| `admin`     | `Admin@2026`   |
| Superviseur   | `emma`      | `Superviseur@2026` |
| Superviseur   | `rabia`     | `Superviseur@2026` |

À la première connexion, un changement de mot de passe est **obligatoire**
avant tout accès au reste de l'application.

---

## 3. Utilisation

### Administrateur
- **Tableau de bord** : statistiques globales et par vendeur.
- **Dossiers** : liste complète, recherche, filtres (état, vendeur,
  compagnie, période), tri, pagination.
- **Nouveau dossier / Modifier** : formulaire complet avec validation
  (numéro de portable unique, champs obligatoires, calcul automatique du
  CA annuel).
- **Dossiers annulés** : vue dédiée avec motif d'annulation.
- **Vendeurs** : création de nouveaux comptes vendeurs (identifiant + mot de
  passe temporaire généré automatiquement et affiché une seule fois),
  activation/désactivation, réinitialisation de mot de passe.
- **Pièces jointes** : ajout/suppression de documents sur chaque dossier
  (PDF, JPG, PNG, DOC, DOCX — 5 Mo maximum).
- **Historique** : chaque création/modification de dossier est journalisée.

### Superviseur
- Accès en lecture à tous les dossiers et aux pièces jointes.
- Peut uniquement modifier `Courrier` et `Commentaire dossier`.

---

## 4. Sécurité mise en œuvre

- **Injections SQL** : 100 % requêtes préparées PDO (`PDO::ATTR_EMULATE_PREPARES`
  désactivé), aucune concaténation de valeurs utilisateur dans une requête.
- **XSS** : toute donnée affichée passe par la fonction d'échappement `e()`
  (`htmlspecialchars`) ; en-tête `Content-Security-Policy` appliqué.
- **CSRF** : jeton unique par session, vérifié sur chaque formulaire POST.
- **Mots de passe** : hachage `password_hash()` (bcrypt), jamais stockés en clair.
- **Sessions** : cookies `HttpOnly`, `SameSite=Lax`, identifiant régénéré à la
  connexion et périodiquement, expiration par inactivité.
- **Brute force** : verrouillage temporaire d'un compte après 5 échecs de
  connexion (15 minutes), journal des tentatives (`login_log`).
- **Contrôle d'accès** : vérification du rôle sur chaque page et chaque
  action serveur (jamais uniquement côté affichage) ; un vendeur ne peut
  jamais accéder aux données d'un autre vendeur, y compris en devinant une URL.
- **Upload de fichiers** : extension ET type MIME réel (`finfo`) vérifiés,
  taille limitée, nom de fichier stocké aléatoire (jamais le nom d'origine),
  fichiers stockés hors d'accès direct et livrés uniquement via un script
  PHP qui revérifie les droits.
- **En-têtes HTTP** : `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Content-Security-Policy`, `Strict-Transport-Security`
  (en production).
- **Fichiers sensibles** : `config/`, `includes/`, `uploads/` et les fichiers
  `.sql` sont bloqués par `.htaccess` (`Require all denied`) — testé et
  vérifié en conditions réelles sous Apache.
- **Compte base de données** : compte applicatif dédié aux droits limités
  (`SELECT/INSERT/UPDATE/DELETE` uniquement sur la base concernée), et non
  le compte administrateur MySQL.

---

## 5. Passage en production

1. Sur votre hébergement, créez une base MySQL et un utilisateur dédié
   (privilèges minimaux) — adaptez `database/install.sql` si votre
   hébergeur impose son propre nom de base/utilisateur.
2. Copiez tous les fichiers du dossier `gestion-dossiers/` vers votre
   répertoire web.
3. Dans `config/config.php` :
   - renseignez les identifiants de connexion à la base de production ;
   - mettez `APP_URL` à l'adresse réelle du site (en `https://`) ;
   - passez `APP_ENV` à `'production'` (désactive l'affichage des erreurs
     PHP au public et active l'en-tête HSTS).
4. Assurez-vous que le certificat HTTPS est actif (les cookies de session
   passent alors automatiquement en mode sécurisé).
5. Vérifiez que le module Apache `mod_headers` et le support des fichiers
   `.htaccess` (`AllowOverride All`) sont actifs sur le serveur — sinon,
   reportez manuellement les règles de `.htaccess` dans la configuration
   du vhost.
6. Changez immédiatement le mot de passe du compte `admin` de démonstration
   (ou supprimez-le et créez votre propre compte administrateur directement
   en base avec `database/generate_password_hash.php`).
7. Supprimez ou protégez `database/generate_password_hash.php` après usage.

---

## 6. Notes de conception

- **Portable unique** : reprise exacte de la règle de validation de données
  Excel `COUNTIF($J$2:$J$75,J2)=1`, appliquée à la fois côté formulaire
  (message d'erreur clair) et par une contrainte `UNIQUE` en base (garantie
  même en cas de contournement du formulaire).
- **CA annuel automatique** : le classeur ne contenait pas de formule
  explicite mais les données observées vérifient systématiquement
  `CA-annuel = CA-mois × 12`. Le champ est pré-rempli automatiquement en
  JavaScript et recalculé côté serveur si la valeur envoyée est absente ou
  invalide, tout en restant modifiable pour les cas particuliers.
- **Référentiel vendeurs** : remplace la macro VBA qui créait automatiquement
  une feuille filtrée à chaque nouveau nom saisi. La page **Vendeurs** ajoute
  maintenant un contact avec son nom et son e-mail, sans compte de connexion.
- **Historique** et **pièces jointes** : fonctionnalités ajoutées par
  rapport au classeur d'origine (qui ne les proposait pas), pour répondre
  aux besoins réels de suivi d'un dossier d'assurance (justificatifs,
  traçabilité des changements de statut) sans dénaturer aucune règle
  métier existante.

---

## 7. Tests effectués

Cette application a été testée de bout en bout avant livraison, avec une
vraie base MySQL et un vrai serveur Apache (règles `.htaccess` incluses) :

- Connexion, changement de mot de passe obligatoire, déconnexion.
- Rejet des requêtes POST sans jeton CSRF valide.
- Création d'un dossier : succès, rejet en cas de portable en doublon,
  calcul automatique du CA annuel.
- Suppression d'un dossier (administrateur uniquement).
- Un vendeur ne peut ni accéder aux pages réservées à l'administrateur, ni
  consulter le dossier d'un autre vendeur, même en connaissant son URL.
- Téléversement de pièce jointe : fichier `.php` déguisé en PDF rejeté,
  fichier PDF valide accepté, téléchargement conforme au contenu d'origine.
- Accès direct aux dossiers `config/`, `includes/`, `uploads/` et aux
  fichiers `.sql` : bloqué (403) par les règles `.htaccess`, vérifié sous
  Apache réel (le serveur de développement intégré de PHP ne respecte pas
  les fichiers `.htaccess` — utilisez toujours Apache, comme fourni par
  XAMPP, pour tester ou exploiter cette protection).
- Création d'un vendeur sans accès de connexion.
>>>>>>> 8b06129 (Initial project import)
