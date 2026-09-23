# Gestion des Dossiers

Application PHP/MySQL de gestion des dossiers d'assurance.

Stack : PHP natif, PDO MySQL, HTML/CSS/JavaScript natif et Apache.

## 1. Deployer sur InfinityFree

### 1.1 Creer la base de donnees

Dans le panneau InfinityFree :

1. Ouvrez **MySQL Databases**.
2. Creez une base de donnees.
3. Notez le nom de la base, l'utilisateur, le mot de passe, le hostname MySQL
   et le port indique par l'hebergeur (habituellement `3306`).
4. N'utilisez pas `localhost` sauf si InfinityFree l'indique explicitement.

Valeurs fournies pour ce site :

```text
Site : https://gestionassur.rf.gd/
Utilisateur : if0_42713899
Hostname : sql301.infinityfree.com
Port : 3306
Base : if0_42713899_gestion_dossiers
Mot de passe : a saisir uniquement dans config/config.php
```

Le mot de passe MySQL ne doit jamais etre committe dans GitHub, dans ce
README, ni dans une capture d'ecran. Comme il a ete partage dans un fichier,
changez-le dans InfinityFree avant la mise en production si possible. Les
champs du fichier fourni semblaient inverses : `sql301.infinityfree.com` est
utilise comme hostname et l'autre valeur comme mot de passe. Confirmez ces
valeurs dans le panneau InfinityFree avant de televerser la configuration.

### 1.2 Importer la base avec phpMyAdmin

InfinityFree ne permet generalement pas a un script SQL de creer une base ou
un utilisateur. `database/install.sql` ne contient donc aucune commande
`CREATE DATABASE`, `CREATE USER`, `GRANT` ou `USE` : selectionnez d'abord la
base dans phpMyAdmin, puis importez le fichier.

Dans phpMyAdmin InfinityFree :

1. Selectionnez `if0_42713899_gestion_dossiers` dans la colonne de gauche.
2. Ouvrez l'onglet **Importer**.
3. Importez `database/install.sql`.
4. Verifiez la presence des tables `users`, `dossiers`,
   `dossier_historique`, `dossier_attachments` et `login_log`.

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
- `config/config.hosting.php` pour InfinityFree ou un autre hebergeur.

Le depot contient `config/config.hosting.example.php` comme modele. Copiez-le
sur le serveur sous le nom `config.hosting.php`, puis renseignez vos valeurs
MySQL. Ce fichier de production est volontairement ignore par GitHub.

Le fichier `config/config.php` choisit automatiquement le bon fichier selon
l'adresse utilisee. Sur le serveur, ouvrez `config/config.hosting.php` et
renseignez les valeurs InfinityFree :

```php
define('DB_HOST', 'HOSTNAME_MYSQL');
define('DB_NAME', 'if0_42713899_gestion_dossiers');
define('DB_USER', 'if0_42713899');
define('DB_PASS', 'VOTRE_MOT_DE_PASSE_MYSQL');
define('DB_CHARSET', 'utf8mb4');
define('APP_URL', 'https://gestionassur.rf.gd');
define('APP_ENV', 'production');
```

Points importants :

- Utilisez le hostname MySQL affiche dans le panneau InfinityFree, meme s'il
  semble inhabituel.
- N'ajoutez pas de slash final a `APP_URL`.
- Ne remplacez pas `DB_HOST` par `localhost` sans confirmation de l'hebergeur.
- Ne televersez jamais `config/config.php` dans un depot public.
- Si un mot de passe a deja ete committe, changez-le et retirez le secret de
  l'historique Git.

### 1.4 Televerser les fichiers

Avec le gestionnaire de fichiers InfinityFree ou un client FTP :

1. Televersez le contenu du projet dans le dossier web, souvent `htdocs/`.
2. Placez `index.php` directement dans ce dossier, sauf installation dans un
   sous-dossier.
3. Televersez `config/config.php` et le fichier local `config/config.hosting.php`
   configure pour la production. Ne televersez pas seulement le contenu du
   depot : le fichier hosting contenant les identifiants est ignore par Git.
4. Conservez `assets/`, `actions/`, `includes/`, `uploads/` et `logs/`.
5. Verifiez que `uploads/dossiers/` et `logs/` sont accessibles en ecriture
   par PHP, selon les permissions autorisees par l'hebergeur.

### 1.4 bis Deploiement par FTP (procedure utilisee)

Le site est deploye par FTP sur `ftpupload.net` (racine web : `htdocs/`).
Identifiants FTP : utilisateur `if0_42713899`, mot de passe identique a celui de
la base (`config/config.hosting.php`). Ne jamais committer ce mot de passe.

Particularites rencontrees sur cet hebergement :

- **La base MySQL n'est PAS joignable depuis l'exterieur** (le hostname
  `sql301.infinityfree.com` ne resout que depuis leur reseau). Impossible de
  modifier les donnees en local : toute modification de donnees passe par un
  script PHP execute SUR le serveur (navigateur), pas par une connexion distante.
- **Les fichiers `.htaccess` sont sensibles a la casse** : un motif
  `FilesMatch "\.(jpg|png|...)$"` refuse `logo.JPG` (majuscules) avec un
  HTTP 403. Les listes d'extensions utilisent desormais le drapeau `(?i)`
  (voir `assets/.htaccess` et `assets/img/.htaccess`).
- **Defi JavaScript anti-bot** : les requetes HTTP simples recoivent une page
  intermediaire de ~850 octets. Utiliser un navigateur (ou un outil rendant le
  JS) pour verifier le site, pas un simple `curl`.

### 1.4 ter Provisionnement des comptes vendeur (base de production)

`provision_vendeurs_admin.php` est un ecran temporaire, protege par un jeton
secret dans l'URL (`?token=...`), qui cree/realigne sur le serveur les comptes
listes dans `readme1.txt` (vendeurs connectables + comptes admin/superviseurs).
Il sert aussi a remettre les mots de passe en conformite avec la politique de
securite (12 caracteres minimum).

Procedure : televerser le fichier par FTP, ouvrir son URL avec le jeton dans le
navigateur, soumettre le formulaire, verifier le rapport, **puis SUPPRIMER le
fichier du serveur**. Il ne doit pas rester en ligne.

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
define('APP_URL', 'https://gestionassur.rf.gd/gestion-dossiers');
```

### 1.6 Verifier le site

1. Ouvrez `https://gestionassur.rf.gd/`.
2. Connectez-vous avec le compte administrateur initial.
3. Changez son mot de passe.
4. Testez la creation d'un dossier, une piece jointe et la deconnexion.
5. Verifiez qu'un vendeur ne voit pas les boutons d'administration ou d'export.

En cas d'erreur MySQL, comparez dans cet ordre avec le panneau InfinityFree :
hostname, nom complet de la base, nom complet de l'utilisateur, mot de passe,
port et permissions. N'utilisez pas les valeurs XAMPP locales en production.

## 2. Deployer chez un autre hebergeur

1. Creez une base MySQL et un utilisateur dans le panneau de l'hebergeur.
2. Donnez a cet utilisateur les droits sur cette base.
3. Selectionnez la base dans phpMyAdmin et importez `database/install.sql`.
4. Si necessaire, supprimez du SQL les commandes `CREATE DATABASE`,
   `CREATE USER`, `GRANT` et `USE`.
5. Configurez `config/config.php` avec les valeurs de production.
6. Televersez les fichiers dans le dossier web.
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
