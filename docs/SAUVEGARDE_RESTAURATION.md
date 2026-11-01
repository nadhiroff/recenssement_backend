# Sauvegarde et restauration

## Sauvegarde

- Automatique chaque nuit : `cron/backup.php`. Sans cron : pseudo-cron, ou bouton « Lancer maintenant » dans **Tâches et sauvegardes**.
- Fichiers : `storage/backups/zocli_quotidienne_AAAAMMJJ_HHMMSS.sql.gz`, avec une copie hebdomadaire le dimanche.
- Rotation : 7 quotidiennes, 4 hebdomadaires.
- `mysqldump` si disponible, sinon repli en PHP pur. Les deux formats comprennent les déclencheurs du journal.

**Ces fichiers contiennent toutes les données personnelles.** Téléchargez régulièrement la dernière sauvegarde hors du serveur et conservez-la dans un lieu sûr, chiffrée si possible.

## Restauration

Par le panneau : **Tâches et sauvegardes**, ligne de la sauvegarde, taper `RESTAURER`, puis confirmer deux fois. Toutes les données actuelles sont remplacées.

En ligne de commande :

```bash
gunzip -c storage/backups/zocli_quotidienne_XXXX.sql.gz | mysql -uUTILISATEUR -p --default-character-set=utf8mb4 recensement_zocli
```

Faire une sauvegarde juste avant toute restauration.

## Essai testé

`php tests/run.php lot3` sauvegarde la base de test, l'endommage (noms supprimés, fiches modifiées, déclencheur retiré), restaure, puis vérifie que toutes les tables métier, le journal, le déclencheur et la colonne générée sont revenus, avec `mysqldump` puis avec le repli PHP. Le cahier des charges demande de refaire cet essai sur l'hébergement réel avant l'ouverture.
