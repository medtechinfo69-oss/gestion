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
un utilisateur. Le fichier `database/install.sql` contient des commandes
locales `CREATE DATABASE`, `CREATE USER`, `GRANT` et `USE` qui peuvent etre
refusees sur un hebergement mutualise.

Dans phpMyAdmin InfinityFree :

1. Selectionnez `if0_42713899_gestion_dossiers` dans la colonne de gauche.
2. Ouvrez l'onglet **Importer**.
3. Importez `database/install.sql`.
4. Si l'import est refuse a cause de `CREATE DATABASE`, `CREATE USER`,
   `GRANT` ou `USE`, ouvrez une copie du fichier et supprimez ces commandes :

```sql
CREATE DATABASE IF NOT EXISTS `gestion_dossiers` ...;
USE `gestion_dossiers`;
CREATE USER ...;
GRANT ...;
FLUSH PRIVILEGES;
```

5. Relancez l'import avec la base deja selectionnee.
6. Verifiez la presence des tables `users`, `dossiers`,
   `dossier_historique`, `dossier_attachments` et `login_log`.

`install.sql` installe le compte administrateur de demonstration. Connectez-
vous avec `admin` et `Admin@2026`, puis changez immediatement ce mot de passe.

Toutes les tables, les comptes initiaux et les donnees de demonstration sont
maintenant reunis dans `database/install.sql`. Pour une base existante, faites
une sauvegarde avant de le reutiliser : il contient des `CREATE TABLE` et des
`INSERT`.

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

## 3. Installation locale avec XAMPP

1. Copiez le projet dans `C:\xampp\htdocs\gestion-dossiers`.
2. Demarrez Apache et MySQL.
3. Ouvrez `http://localhost/phpmyadmin`.
4. Executez `database/install.sql` depuis l'onglet **SQL**.
5. Conservez `config/config.local.php` pour la configuration XAMPP. `config.php`
   chargera automatiquement ce fichier sur `localhost`.

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'gestion_dossiers');
define('DB_USER', 'gestion_app');
define('DB_PASS', 'ChangeMoi_2026!');
define('APP_URL', 'http://localhost/gestion-dossiers');
define('APP_ENV', 'development');
```

6. Ouvrez `http://localhost/gestion-dossiers/`.

## 4. Securite avant la mise en production

- Passez `APP_ENV` a `production`.
- Changez le mot de passe administrateur de demonstration.
- Utilisez un mot de passe MySQL unique et non partage.
- Ne publiez jamais `config/config.php`, les mots de passe ou les exports.
- Gardez HTTPS active et verifiez que `.htaccess` est pris en charge.
- Faites une sauvegarde avant toute migration.

## 5. Fichiers SQL

| Fichier | Usage |
|---|---|
| `database/install.sql` | Tables, roles, Corbeille et donnees de demonstration |

Pour une base deja en production, n'importez pas `install.sql` sans sauvegarde
et sans verifier les `CREATE TABLE` et `INSERT` presents dans le fichier.
