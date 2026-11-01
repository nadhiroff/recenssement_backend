# Installation

## 1. Poste Ubuntu local

Prérequis : Apache avec `rewrite`, `headers`, `deflate` ; PHP ≥ 8.1 avec `pdo_mysql`, `mbstring`, `openssl`, `zlib` (`intl` conseillé) ; MySQL 8 ou MariaDB ≥ 10.4 ; Node 20+.

```bash
sudo apt install apache2 php libapache2-mod-php php-mysql php-mbstring php-intl mysql-server
sudo a2enmod rewrite headers deflate
```

**Obligatoire : autoriser les `.htaccess`.** Sans cela, `storage/` (journaux, sauvegardes), `database/` et `config/` sont accessibles depuis le web.

```bash
printf '<Directory /var/www/html/recenssement_famille-Backend>\n    AllowOverride All\n</Directory>\n' \
  | sudo tee /etc/apache2/conf-available/recensement-zocli.conf
sudo a2enconf recensement-zocli && sudo systemctl reload apache2
```

Vérification : `curl -I http://localhost/recenssement_famille-Backend/storage/logs/otp.log` doit répondre 403.

Base et configuration :

```bash
cd /var/www/html/recenssement_famille-Backend
cp config/config.example.php config/config.php     # puis renseigner BDD et SMTP
mysql -uadmin -p -e "CREATE DATABASE recensement_zocli CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -uadmin -p --default-character-set=utf8mb4 recensement_zocli < database/schema.sql
mysql -uadmin -p --default-character-set=utf8mb4 recensement_zocli < database/seed_demo.sql   # démonstration seulement
php cron/create_admin.php votre@email.org
chgrp -R www-data storage && chmod -R g+rwX storage
```

> `schema.sql` commence par supprimer les tables : ne jamais le rejouer sur une base en service.
> Un seul administrateur existe : il se crée en base avec `php cron/create_admin.php` et ne se nomme pas depuis le panneau. Le panneau web lui est réservé. `ADMIN_OTP_REQUIS` (`config.php`) impose le code par email hors développement : en production, utilisez une adresse que vous contrôlez et un mot de passe solide.
>
> `seed_demo.sql` est fictif (admin de démonstration `admin.demo@example.test` / `DemoAdmin2026!`) : à ne jamais importer en production.

Mise à jour d'une base existante : appliquer dans l'ordre les fichiers de `database/migrations/`.

Tâches planifiées (`crontab -e`) :

```
0 2 * * 1  php /var/www/html/recenssement_famille-Backend/cron/detect_doublons.php
0 8 * * *  php /var/www/html/recenssement_famille-Backend/cron/relances.php
30 1 * * * php /var/www/html/recenssement_famille-Backend/cron/backup.php
```

Application : voir le dépôt frontend.

```bash
cd ~/recenssement_famille-Frontend
npm install
npx expo start            # téléphone sur le même Wi-Fi : scanner le QR code avec Expo Go
npm run export:pwa        # construit la PWA et la copie dans le dossier app/ du backend
```

En développement, l'application trouve seule l'adresse du PC. Émulateur Android sans serveur Expo : `10.0.2.2`, voir `src/config.ts`. Le code OTP des comptes de démonstration (`*.test`) n'est pas envoyé : il se lit à la fin de `storage/logs/otp.log`.

Tests : `php tests/run.php` (base `recensement_zocli_test` recréée à chaque lancement ; le test Gramps utilise l'image Docker `zocli-gramps:local`, sinon il échoue et le signale).

### Lien d'invitation WhatsApp

WhatsApp ne rend cliquable qu'un vrai nom de domaine. En production, `APP_URL` doit donc être un domaine en `https`. En développement, une adresse IP locale passe par `nip.io` (`http://192.168.1.6.nip.io/...`), qui reste limité au même réseau Wi-Fi que le PC.

### Alertes système

Elles passent par le service Expo (`PUSH_DRIVER = 'expo'`, projet `@nadhir_off/recensement-zocli`).

- Expo Go sur iPhone : à essayer tel quel.
- Expo Go sur Android : ne reçoit pas de push. Application ouverte, l'alerte est produite sur le téléphone.
- Vraie application : `eas build`. Il faut une clé APNs (compte Apple Developer payant) pour iPhone et une clé FCM (Firebase) pour Android, à déclarer avec `eas credentials`.
- PWA : pas d'alerte système ; les alertes restent visibles dans l'application.

## 2. Hébergeur mutualisé

1. Envoyer par FTP le dossier du backend, sans `tests/`, `.git/`, `docs/`.
2. Dans le gestionnaire de bases, créer la base puis importer `database/schema.sql` avec phpMyAdmin (les déclencheurs du journal doivent être autorisés : vérifier que l'import ne signale pas d'erreur sur `journal_no_update`).
3. Copier `config.example.php` en `config.php` : `APP_ENV` à `'prod'`, `APP_URL` et `PWA_URL` en https, BDD, SMTP, `CORS_ALLOWED_ORIGINS`.
4. Vérifier que `.htaccess` est actif (`/storage/logs/` doit répondre 403).
5. Créer l'administrateur : `php cron/create_admin.php` par SSH. Sans SSH, générer un hash localement avec `password_hash` et l'insérer dans `utilisateurs` par phpMyAdmin.
6. Cron : déclarer les trois tâches. Sans cron, le pseudo-cron les rattrape à chaque visite de l'administrateur.
7. PWA : avant `npm run export:pwa`, adapter `experiments.baseUrl` de `app.json` au chemin réel (`/app` si le backend est à la racine du domaine) et donner en argument le dossier cible.
8. Faire un essai de restauration avant l'ouverture (voir `SAUVEGARDE_RESTAURATION.md`).
