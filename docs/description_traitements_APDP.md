# Description des traitements pour le dossier APDP

Document de travail à compléter par le responsable désigné par la famille, avant vérification des formalités auprès de l'Autorité de Protection des Données Personnelles du Bénin.

**Responsable du traitement :** collectivité familiale ZOCLI de Sahouè Doutou, représentée par : à désigner par le conseil de famille.

**Finalités :** recenser les membres de la famille ; reconstituer et conserver l'arbre généalogique ; vérifier les liens de parenté ; permettre aux membres de consulter l'arbre validé.

| Catégorie | Données | Personnes concernées |
|---|---|---|
| Identité | noms de famille, prénoms, noms vodoun et de baptême, surnoms, sexe | membres, parents, enfants mineurs, défunts, aînés |
| Dates | année de naissance et de décès, avec précision | idem |
| Coordonnées | numéro WhatsApp, email, lieu de résidence | inscrits |
| Famille | liens de filiation, unions, rang d'épouse, branche | idem |
| Traces | journal des actions, adresse IP, consentement horodaté | utilisateurs |

**Points d'attention :** les noms vodoun peuvent révéler une appartenance religieuse et la filiation peut être sensible : ces points sont à examiner avec l'APDP. Les données de mineurs sont saisies par un parent.

**Base légale :** consentement horodaté à l'inscription ; pour un tiers (enfant mineur, parent décédé, aîné), déclaration tracée du déclarant, avec présence de l'aîné attestée par le relais. Le statut juridique du consentement recueilli pour un tiers est à valider.

**Destinataires :**
- membres : arbre validé, sans téléphone, sans email, sans lieu de résidence, sans fiche privée ;
- référents : fiches et téléphones de leur branche ;
- administrateur général : ensemble des données ;
- hébergeur et prestataire d'envoi d'emails, en sous-traitance (à préciser).
Les membres vivant à l'étranger et l'hébergement éventuel hors du Bénin sont à examiner au titre des transferts.

**Durées de conservation (à valider) :** fiches et arbre pendant la durée de vie du projet familial ; codes de connexion 10 minutes ; sessions 30 jours ; invitations 30 jours ; journal : à fixer ; sauvegardes : rotation sur 7 jours et 4 semaines.

**Droits des personnes :** correction de la fiche depuis l'application ; retrait de la fiche (masquage, effacement du téléphone et de l'email de la fiche, consentement marqué retiré, compte désactivé) ; demandes à adresser à l'administrateur général. Une demande d'effacement définitif doit être traitée à part, car le retrait masque la fiche sans la supprimer.

**Mesures de sécurité :** accès par rôle contrôlé côté serveur ; codes et jetons stockés hachés ; mots de passe bcrypt ; requêtes préparées ; jeton CSRF et cookies durcis sur le panneau ; limitation de débit ; journal non modifiable par déclencheurs SQL ; aucun numéro dans les journaux techniques ; sauvegarde quotidienne ; HTTPS obligatoire en production.
