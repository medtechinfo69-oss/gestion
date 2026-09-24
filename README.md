# Gestion des Dossiers

Application PHP/MySQL de gestion des dossiers d'assurance.

Stack : PHP natif, PDO MySQL, HTML/CSS/JavaScript natif et Apache.

## 1. Deployer sur ByetHost (assurialis-app.byethost22.com)

### 1.1 Compte, FTP et base de donnees

Dans le panneau ByetHost (vPanel) :

1. Ouvrez **MySQL Databases**.
2. Creez une base de donnees : son nom est prefixe par le compte.
3. Notez le nom de la base, l'utilisateur, le mot de passe et le hostname MySQL.
4. N'utilisez pas `localhost` : chez cet hebergeur MySQL n'ecoute que sur son
   reseau interne (voir 1.4 bis).

Valeurs de ce site :

```text
Site : https://assurialis-app.byethost22.com/
FTP : ftpupload.net (racine web : /htdocs/)
Compte / utilisateur MySQL : b22_42998523
Base : b22_42998523_assurialis
Hostname MySQL : sql206.byethost22.com
Port : 3306
Mot de passe : uniquement dans .vscode/sftp.json (FTP) et
               config/config.hosting.php (MySQL) — jamais dans GitHub
```

Le mot de passe ne doit jamais etre committe dans GitHub, dans ce README ni
dans une capture d'ecran : les deux fichiers ci-dessus sont ignores par git.

### 1.2 Importer la base

ByetHost ne permet generalement pas a un script SQL de creer une base ou un
utilisateur. `database/install.sql` ne contient donc aucune commande
`CREATE DATABASE`, `CREATE USER`, `GRANT` ou `USE` : selectionnez d'abord la
base, puis importez le fichier.

**Methode A — phpMyAdmin (recommandee) :**

1. vPanel -> **MySQL Databases** -> lancez **phpMyAdmin**.
2. Selectionnez `b22_42998523_assurialis` dans la colonne de gauche.
3. Ouvrez l'onglet **Importer**, choisissez `database/install.sql`, validez.
4. Verifiez la presence des tables `users`, `dossiers`,
   `dossier_historique`, `dossier_attachments` et `login_log`.

**Methode B — `scripts/db_setup.php` (depuis le serveur) :** indispensable
quand phpMyAdmin n'est pas joignable. Televersez ce fichier a la racine du
site (`/htdocs/db_setup.php`), puis ouvrez :

```text
https://assurialis-app.byethost22.com/db_setup.php?token=LE_JETON&check=1
```

pour un diagnostic sans ecriture (PHP, extensions, droits d'ecriture sur
`uploads/` et `logs/`, tables presentes, comptes), et :

```text
https://assurialis-app.byethost22.com/db_setup.php?token=LE_JETON&confirm=oui
```

pour appliquer `database/install.sql`. **Supprimez le fichier du serveur
immediatement apres** : le dossier `scripts/` est de toute facon protege par
`Require all denied`.

`install.sql` installe le compte administrateur de demonstration. Connectez-
vous avec `admin` et `Admin@2026`, puis changez immediatement ce mot de passe.

Toutes les tables, les comptes initiaux et les donnees de demonstration sont
maintenant reunis dans `database/install.sql`. Le fichier est **non
destructif** : il utilise `CREATE TABLE IF NOT EXISTS` et `INSERT IGNORE`,
donc une table ou une donnee deja presente n'est jamais ecrasee. Vous pouvez
l'importer sans risque sur une base deja en production pour ajouter seulement
les tables manquantes. Pour reparer les cles primaires d'une base existante,
voir la section 5.

### 1.3 Configurer la connexion PHP

Le projet utilise deux fichiers locaux, non publies dans GitHub :

- `config/config.local.php` pour XAMPP ;
- `config/config.hosting.php` pour l'hebergement (ByetHost).

Le depot contient `config/config.hosting.example.php` comme modele. Copiez-le
sur le serveur sous le nom `config.hosting.php`, puis renseignez vos valeurs
MySQL. Ce fichier de production est volontairement ignore par GitHub.

Le fichier `config/config.php` choisit automatiquement le bon fichier selon
l'adresse utilisee (IP locale/LAN => `config.local.php`, nom de domaine =>
`config.hosting.php`). Valeurs de production de ce site :

```php
define('DB_HOST', 'sql206.byethost22.com');
define('DB_NAME', 'b22_42998523_assurialis');
define('DB_USER', 'b22_42998523');
define('DB_PASS', 'VOTRE_MOT_DE_PASSE_MYSQL');
define('DB_CHARSET', 'utf8mb4');
define('APP_URL', 'https://assurialis-app.byethost22.com');
define('APP_ENV', 'production');
define('CACHE_VERSION', '20260924'); // a incrementer a chaque deploiement
```

Points importants :

- Utilisez le hostname MySQL affiche dans le vPanel, meme s'il semble
  inhabituel (`sql206.byethost22.com`) : il n'est resolvable que depuis le
  reseau de l'hebergeur.
- `APP_URL` doit correspondre exactement au domaine public : tous les
  formulaires, redirections et liens `canonical` sont construits avec lui.
- N'ajoutez pas de slash final a `APP_URL`.
- Ne remplacez pas `DB_HOST` par `localhost` sans confirmation de l'hebergeur.
- Ne televersez jamais `config/config.php` (ni `config.hosting.php`) dans un
  depot public.
- Si un mot de passe a deja ete committe, changez-le et retirez le secret de
  l'historique Git.

### 1.4 Televerser les fichiers

**Deploiement automatise (recommande)** — `scripts/deploy_ftp.php` lit les
identifiants FTP dans `.vscode/sftp.json` (fichier local, ignore par git) :

```powershell
php scripts/deploy_ftp.php check             # teste la connexion FTP
php scripts/deploy_ftp.php list /htdocs/     # liste le dossier web
php scripts/deploy_ftp.php upload --dry-run  # simulation, rien n'est envoye
php scripts/deploy_ftp.php upload            # televerse tout le projet
php scripts/deploy_ftp.php upload --only=config/ --force   # un seul dossier
```

Le script cree les dossiers manquants, ignore les fichiers deja a jour
(comparaison de taille), verifie la taille apres envoi et affiche un rapport
(envoyes / deja a jour / echecs). Il exclut volontairement `.git/`, `.vscode/`,
`logs/*.log`, le contenu de `uploads/dossiers/` (donnees locales) et
`readme*.txt` (identifiants en clair).

**Avec un client FTP (FileZilla, extension VS Code SFTP) :**

1. Televersez le contenu du projet dans `/htdocs/` — jamais a la racine du
   compte FTP, ou l'hebergeur place un fichier `DO NOT UPLOAD FILES HERE`.
2. Placez `index.php` directement dans ce dossier, sauf installation dans un
   sous-dossier.
3. Televersez `config/config.php` et le fichier local `config/config.hosting.php`
   configure pour la production. Ne televersez pas seulement le contenu du
   depot : le fichier hosting contenant les identifiants est ignore par Git.
4. Conservez `assets/`, `actions/`, `includes/`, `uploads/`, `logs/`,
   `scripts/` et `database/` (ces dossiers sont proteges par `.htaccess`).
5. Verifiez que `uploads/dossiers/`, `uploads/tmp/` et `logs/` sont accessibles
   en ecriture par PHP (voir 1.2, methode B, mode `&check=1`).

### 1.4 bis Particularites de l'hebergement ByetHost

- **La base MySQL n'est PAS joignable depuis l'exterieur** : `sql206.byethost22.com`
  ne resout que depuis le reseau de l'hebergeur (verifie : nom absent du DNS
  public). Impossible d'importer la base ou de modifier les donnees depuis un
  poste local : importez `database/install.sql` avec phpMyAdmin ou
  `scripts/db_setup.php` (voir 1.2), et executez les outils de maintenance
  (`database/repair_schema.php`, `scripts/provision_vendeurs.php`) via leurs
  ecrans navigateur equivalents.
- **PHP 8.4 / MariaDB 11.4.** Les extensions `pdo_mysql`, `mbstring`,
  `fileinfo`, `zip`, `openssl`, `curl` et `gd` sont actives, mais plusieurs
  fonctions sont interdites par l'hebergeur : `exec`, `system`, `shell_exec`,
  `sleep`, `set_time_limit`, `getallheaders`, `curl_multi_exec`,
  `ini_get_all`, `pfsockopen`, les fonctions `socket_*`. Le code actuel ne les
  utilise pas : ne les introduisez pas dans un nouveau module.
- **Optimisation automatique des images** : lors de l'envoi d'un `.jpg` ou
  `.png` par FTP, le serveur re-encode l'image (la taille au repos differe de
  la taille locale, l'image reste valide). Les autres fichiers (PHP, CSS, JS,
  `.htaccess`) sont transferes octet pour octet : une difference de taille
  constatee sur une image n'est donc pas une erreur de deploiement.
- **Defi JavaScript anti-bot** (iFastNet) : une requete HTTP simple recoit une
  page intermediaire d'environ 850 octets qui pose un cookie `__test` en
  JavaScript. Utilisez un navigateur (ou un client qui resout le defi) pour
  verifier le site, pas un simple `curl`.
- **Les fichiers `.htaccess` sont sensibles a la casse** : un motif
  `FilesMatch "\.(jpg|png|...)$"` refuse `logo.JPG` (majuscules) avec un
  HTTP 403. Les listes d'extensions utilisent desormais le drapeau `(?i)`
  (voir `assets/.htaccess` et `assets/img/.htaccess`).

### 1.4 ter Provisionnement des comptes vendeur (base de production)

`provision_vendeurs_admin.php` est un ecran temporaire, protege par un jeton
secret dans l'URL (`?token=...`), qui cree/realigne sur le serveur les comptes
listes dans `readme.txt` (vendeurs connectables + comptes admin/superviseurs).
Il sert aussi a remettre les mots de passe en conformite avec la politique de
securite (12 caracteres minimum).

`database/install.sql` ne cree que 8 comptes (admin, emma, rabia, christine,
helene, justine, laurence, nina). Apres une installation neuve, utilisez cet
ecran pour creer les vendeurs documentes dans `readme.txt` (alice, sonia,
emilie, eva, rosa, ...).

Procedure : televerser le fichier par FTP, ouvrir son URL avec le jeton dans le
navigateur, soumettre le formulaire, verifier le rapport, **puis SUPPRIMER le
fichier du serveur**. Il ne doit pas rester en ligne. Le jeton de ce fichier est
un secret : s'il a circule, remplacez-le avant usage.

### 1.5 Sauvegarder et restaurer les donnees

Depuis **Mon profil** avec un compte administrateur :

- **Télécharger une sauvegarde** exporte les tables applicatives dans un
   fichier JSON.
- **Importer une sauvegarde** ajoute ou met a jour les donnees du fichier dans
   la base courante.

Faites une sauvegarde avant chaque import et utilisez uniquement un fichier
produit par cette application. Le fichier contient les hash des mots de passe
pour restaurer les comptes : protegez-le comme une donnee sensible et
supprimez-le apres transfert.

Si le site est installe dans un sous-dossier, adaptez par exemple :

```php
define('APP_URL', 'https://assurialis-app.byethost22.com/gestion-dossiers');
```

### 1.6 Verifier le site

1. Ouvrez `https://assurialis-app.byethost22.com/` (redirection vers
   `login.php`).
2. Connectez-vous avec le compte administrateur initial (`admin` /
   `Admin@2026`). Comme le mot de passe n'a pas ete change depuis plus de
   90 jours, l'application impose sa modification : renseignez un mot de passe
   conforme (12 caracteres, majuscule, minuscule, chiffre, caractere special).
3. Testez la creation d'un dossier, une piece jointe et la deconnexion.
4. Verifiez qu'un vendeur ne voit pas les boutons d'administration ou d'export.
5. Ouvrez `scripts/db_setup.php?token=...&check=1` (voir 1.2) pour confirmer
   que les 26 tables sont presentes et que `uploads/` et `logs/` sont
   inscriptibles.

En cas d'erreur MySQL, comparez dans cet ordre avec le vPanel ByetHost :
hostname, nom complet de la base, nom complet de l'utilisateur, mot de passe,
port et permissions. N'utilisez pas les valeurs XAMPP locales en production.

### 1.7 Migration InfinityFree -> ByetHost (deploiement effectue)

Recapitulatif de la migration vers `https://assurialis-app.byethost22.com`
(compte `b22_42998523`, base `b22_42998523_assurialis`) :

1. `.vscode/sftp.json` : hote `ftpupload.net`, utilisateur `b22_42998523`,
   racine distante `/htdocs/`.
2. `config/config.hosting.php` : `DB_HOST=sql206.byethost22.com`,
   `DB_NAME=b22_42998523_assurialis`, `DB_USER=b22_42998523`, `APP_URL` sur le
   nouveau domaine, `CACHE_VERSION` incremente.
3. `php scripts/deploy_ftp.php upload` : 115 fichiers / 13 dossiers transferes
   (hors `.git`, `.vscode`, journaux et donnees locales d'`uploads/dossiers`).
4. `database/install.sql` applique sur la base neuve via
   `scripts/db_setup.php?confirm=oui` : 36 instructions, 26 tables, 8 comptes,
   aucune erreur.
5. Verifications : page de connexion servie, connexion `admin` validee
   (redirection vers le changement de mot de passe), `dossiers.php` rendu,
   `config/`, `database/`, `scripts/` et `readme.txt` refuses en HTTP 403.

Reste a faire cote exploitation : creer les vendeurs manquants avec
`provision_vendeurs_admin.php` (voir 1.4 ter), puis **supprimer** ce fichier du
serveur, et changer le mot de passe MySQL s'il a circule.

## 2. Deployer chez un autre hebergeur

1. Creez une base MySQL et un utilisateur dans le panneau de l'hebergeur.
2. Donnez a cet utilisateur les droits sur cette base.
3. Selectionnez la base dans phpMyAdmin et importez `database/install.sql`
   (ou utilisez `scripts/db_setup.php`, voir 1.2).
4. Si necessaire, supprimez du SQL les commandes `CREATE DATABASE`,
   `CREATE USER`, `GRANT` et `USE`.
5. Configurez `config/config.hosting.php` avec les valeurs de production
   (`config/config.php` choisit automatiquement le fichier selon le domaine).
6. Televersez les fichiers avec `php scripts/deploy_ftp.php upload`.
7. Activez HTTPS et reglez `APP_URL` sur l'adresse publique exacte.

Activez les extensions PHP `pdo_mysql`, `fileinfo` et `zip`, necessaires a la
base de donnees, aux uploads et a l'export Excel.

### 2.1 Notifications e-mail

Les alertes utilisent Gmail SMTP et sont envoyees a `medtechinfo69@gmail.com`
ainsi qu'aux adresses des comptes admin actifs. Utilisez un mot de passe
d'application Google, jamais le mot de passe normal du compte :

```text
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=medtechinfo69@gmail.com
MAIL_PASSWORD=votre_mot_de_passe_application_google
MAIL_ENCRYPTION=tls
MAIL_FROM=medtechinfo69@gmail.com
MAIL_ALERT_TO=medtechinfo69@gmail.com
```

Un export RH admin est envoye en piece jointe par e-mail et n'est pas
telecharge dans le navigateur. Les modifications faites par un superviseur sur un dossier,
les changements de compte ou mot de passe superviseur, et les pieces jointes
ajoutees declenchent aussi une alerte. Les erreurs SMTP sont journalisees sans
bloquer l'action principale.

## 3. Installation locale avec XAMPP

1. Copiez le projet dans `C:\xampp\htdocs\gestion-dossiers-new`.
2. Demarrez Apache et MySQL.
3. Ouvrez `http://localhost/phpmyadmin`.
4. Executez `database/install.sql` depuis l'onglet **SQL**.
5. Conservez `config/config.local.php` pour la configuration XAMPP. `config.php`
   chargera automatiquement ce fichier sur `localhost`.

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_dossiers-new');
define('DB_USER', 'gestion_app');
define('DB_PASS', 'ChangeMoi_2026!');
define('APP_URL', 'http://localhost/gestion-dossiers-new');
define('APP_ENV', 'development');
```

6. Ouvrez `http://localhost/gestion-dossiers-new/`.

## 4. Securite avant la mise en production

- Passez `APP_ENV` a `production`.
- Changez le mot de passe administrateur de demonstration.
- Utilisez un mot de passe MySQL unique et non partage.
- Ne publiez jamais `config/config.php`, les mots de passe ou les exports.
- Gardez HTTPS active et verifiez que `.htaccess` est pris en charge.
- Faites une sauvegarde avant toute migration.

## 5. Fichiers de base de donnees

Le dossier `database/` ne contient plus que deux fichiers utiles (plus son
`.htaccess` de protection). Tous les anciens fichiers separes
(`install_hosting.sql`, `chat_tables.sql`, sauvegardes `*.sql`, scripts de
reparation) ont ete fusionnes.

| Fichier | Usage |
|---|---|
| `database/install.sql` | **Installation complete** : creation des 26 tables + donnees initiales (origines, parametres, comptes). Non destructif et idempotent. |
| `database/repair_schema.php` | **Reparation** d'une base existante : restaure les `PRIMARY KEY` / `AUTO_INCREMENT` perdus et ajoute les cles `UNIQUE` manquantes (`settings`, `rate_limits`, `chat_presence`, `salary_records`, ...). Aucune ligne metier n'est supprimee. |
| `repair_schema_admin.php` | **Meme reparation, en un clic depuis le navigateur** (menu admin → « Réparation BDD »). Reserve aux administrateurs (session + CSRF). Indispensable sur un hebergement sans SSH comme InfinityFree. Contient aussi une carte **« Nettoyage du chat (urgence) »** : bouton pour supprimer les conversations (`chat_messages` / `chat_presence`) quand la table du chat est corrompue ou sature l'espace de l'hebergement — voir `chat_cleanup_all()` dans `includes/chat.php`. |

Pour reparer une base existante, executez le script en ligne de commande :

```bat
php database/repair_schema.php
```

Sous XAMPP, depuis la racine du projet :

```bat
C:\xampp\php\php.exe database\repair_schema.php
```

Le dossier `database/` est protege par un `.htaccess` (`Require all denied`) :
ces outils ne sont donc accessibles que par la ligne de commande, jamais par
le navigateur. Le script est idempotent : il peut etre relance autant de fois
que necessaire.

Symptomes typiques d'un schema a reparer : chat qui affiche « Erreur d'envoi »
ou « non envoye - reessayez », « Demande invalide. » a l'approbation d'une
session superviseur, parametres de securite dupliques, echec d'import de
dossiers, piece jointe non enregistree.

Si le schema est repeare mais que le chat reste bloque (ou que l'espace
d'hebergement sature), l'ecran `repair_schema_admin.php` propose aussi le
nettoyage d'urgence du chat : il supprime les conversations (definitivement),
purge la presence « en ligne » (auto-regeneree) et rend l'espace disque a
l'hebergeur via `OPTIMIZE TABLE`. Une table chat videe repart avec un
`AUTO_INCREMENT` neuf : cela corrige aussi « Erreur d'envoi ».
