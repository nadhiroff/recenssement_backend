# Rapport de tests

Dernière exécution : 1er novembre 2026, sur PHP 8.5 et MySQL 8.4.

| Suite | Résultat |
|---|---|
| Backend et admin web (`php tests/run.php`) | 113 réussis, 0 échoué |
| Frontend Jest (`npx jest`) | 21 réussis, 0 échoué |
| TypeScript, ESLint, `expo-doctor` | sans erreur (20/20 pour expo-doctor) |
| `expo export` Android et web | sans erreur |
| Gramps 6.0.6 (Docker) | « GEDCOM import report: No errors detected » |
| `.htaccess` dans Apache avec AllowOverride All, PHP 8.1 (Docker) | fichiers sensibles en 403, `.git` en 404, réécriture OK, syntaxe OK |
| Chrome mobile 360×640, 3G et CPU divisé par 4 | parcours complet, coupure réseau, réouverture hors ligne, rejeu, modification, déconnexion Oui/Non, retrait, fiche depuis l'arbre : OK |

| Chrome, parcours du proche | invitation WhatsApp, page du proche sans compte, Oui, alerte référent, validation, alerte déclarant, statut Validé : OK |
| Chrome, panneau admin 1366×768 | 11 pages sans défilement, aucun bouton souligné, déconnexion Oui/Non : OK |

## Scénarios de recette

| Scénario | Résultat | Preuve |
|---|---|---|
| 1. Inscription et rejeu sans doublon | Réussi | tests API, envois simultanés, Chrome avec coupure réseau |
| 2. Kossi, 1968, bon grand-père | Réussi | par identifiant du grand-père et par son nom |
| 3. Deux épouses proposées | Réussi | API, Jest, Chrome |
| 4. Doublon ZOCLI/ZOKLI | Réussi | tâche hebdomadaire |
| 5. Fusion puis annulation | Réussi | comparaison ligne à ligne de 9 tables, horodatages compris |
| 6. Ni téléphone ni fiche privée | Réussi | balayage automatique de toutes les routes GET : 284 appels |
| 7. Référent et conflit d'intérêts | Réussi | 403, orientation, puis validation par l'autre référent |
| 8. GEDCOM dans Gramps | Réussi | import réel, liens validés seulement |
| 9. Journal, paire distincte, relance, poids | Réussis | tests dédiés |

## Mesures Chrome (3G simulée, CPU divisé par 4)

| Mesure | Résultat | Objectif |
|---|---|---|
| Première visite PWA (Apache, gzip) | 10,2 s | < 2 s : **non atteint** |
| Visites suivantes (service worker) | 1,4 s | < 2 s : atteint |
| Volume d'une première visite | environ 650 Ko compressés | |

## Non testé

- Téléphone Android réel et émulateur : la recette du cahier des charges exige ce test. L'application a été utilisée sur iPhone par le commanditaire, sans test automatisé.
- Barre clavier : elle n'existe pas sur le web et n'a donc pas été testée automatiquement, seulement à vérifier sur iPhone.
- Durée d'une inscription par une vraie personne, à chronométrer : l'objectif est de moins de 5 minutes.
- Alertes système réelles : mécanisme testé côté serveur et texte contrôlé, mais réception à vérifier sur téléphone. Glissement arrière iOS et barre clavier : à vérifier sur iPhone.
- Envoi réel d'un email : seule l'authentification SMTP a été vérifiée.
- Hébergement mutualisé réel.

## Corrections apportées grâce aux tests

- Le brouillon ne se sauvegardait pas (`form.watch` remplacé par `form.subscribe`).
- Un brouillon incomplet était jeté à la relecture, avec perte du consentement.
- Fausse détection « Hors ligne » due à la sonde NetInfo vers Google, supprimée.
- Le service worker ne couvrait pas la première visite : pré-cache complet ajouté.
- Redirection vers le consentement après un envoi, à cause d'un profil en cache périmé.
- Hash du compte de démonstration corrompu par `preg_replace`.
- Membres recevant 404 ou 422 au lieu de 403 sur les doublons.
- Droits partagés sur `storage/` entre Apache et les tâches ; gris `muted` foncé à `#646D67` pour atteindre le contraste AA.

- « Se déconnecter » ne fermait pas la session et laissait l'utilisateur sur un écran sans accès, d'où « Connexion requise ».
- Référents limités à leur branche dans l'arbre, inscrit non rapproché de sa propre fiche, union et consentement absents du journal : voir `CONFORMITE_CAHIER_DES_CHARGES.md`.

## Liste des tests backend

- ✓ Normalisation : accents, majuscules, ponctuation
- ✓ Normalisation : ZOKLI et ZOCKLI ramenés à ZOCLI
- ✓ Normalisation : variante ajoutée sans redéploiement, recalcul
- ✓ Score : chaque critère vaut son poids
- ✓ Score : poids modifiés en base, sans redéploiement
- ✓ Téléphone : format béninois +22901, ancien numéro converti, autres pays acceptés
- ✓ OTP : code haché, connexion, /me
- ✓ OTP : premier accès sans email refusé
- ✓ OTP : 5 tentatives maximum, code réutilisé refusé, expiration
- ✓ OTP : limitation de débit par numéro
- ✓ OTP : un compte existant ne peut pas être détourné vers un autre email
- ✓ Numéro de téléphone absent des journaux techniques
- ✓ Déconnexion : jeton révoqué ; sans jeton 401
- ✓ Routage de repli ?route= quand la réécriture est absente
- ✓ Accès : adresse masquée, compte retiré signalé, session prolongée à l'usage
- ✓ Email de connexion : changé seulement après le code reçu sur la nouvelle adresse
- ✓ Recette 1 : inscription complète, rejeu même client_uuid sans doublon
- ✓ Envois simultanés du même client_uuid : une seule fiche
- ✓ Inscription : sans consentement refusée, rien de créé
- ✓ Inscription : fiche ressemblante non vue → 409 avec candidats ; annulation complète
- ✓ Une personne n'existe qu'une fois : l'inscrit se reconnaît dans sa fiche existante
- ✓ Inscription : deux noms supplémentaires servent à la recherche
- ✓ Inscription : année approximative et date absente acceptées
- ✓ Lien : un seul père actif par enfant (contrainte en base)
- ✓ Recette 2 : « Kossi », né vers 1968, bon grand-père → fiche proposée
- ✓ Rapprochement : sous le seuil, aucune proposition
- ✓ Rapprochement : prénom retrouvé via un nom secondaire (vodoun)
- ✓ Rapprochement : fiche privée anonymisée pour un membre
- ✓ Rapprochement : limité en débit (anti-aspiration)
- ✓ Recette 3 : un père à deux épouses les fait apparaître toutes les deux
- ✓ Union : créée à l'inscription, rang suivant attribué
- ✓ Date complète facultative : année seule, date entière, date impossible refusée
- ✓ Tiers : enfant mineur rattaché au déclarant, trace conservée
- ✓ Tiers : parent décédé marqué décédé et relié au déclarant
- ✓ Tiers : aîné réservé au relais de la branche, présence obligatoire
- ✓ Fiche : le membre corrige la sienne, pas celle d'un autre
- ✓ Fiche : retrait masque la fiche, révoque la session, garde le journal
- ✓ Fiche : le référent de la branche voit le téléphone, celui d'une autre non
- ✓ Fiche privée : référent journalisé, membre 404
- ✓ Transitions autorisées et interdites
- ✓ Un lien déclaré ne peut pas être validé directement (409)
- ✓ Rejet : motif obligatoire, puis lien terminal et nouveau lien possible
- ✓ Recette 7 : un référent ne valide pas un lien qui le concerne, un autre le peut
- ✓ Recette 7 : même règle pour un lien qui concerne ses enfants
- ✓ Conflit : file du référent signale les liens à traiter par un autre
- ✓ L'admin est soumis à la même règle
- ✓ Référent : droits limités à sa branche ; membre interdit
- ✓ Validation du père et de la mère : l'union passe en validée
- ✓ Invitation : URL wa.me à jeton unique, jeton haché en base
- ✓ Invitation : le lien s'ouvre sur le téléphone du proche, pas sur localhost
- ✓ Invitation : réservée au déclarant
- ✓ Invitation : on ne peut pas s'inviter soi-même
- ✓ Confirmation : Oui fait passer le lien à confirmé, sans compte, une seule fois
- ✓ Confirmation : Non envoie en examen, Je ne sais pas ne change rien
- ✓ Confirmation : le déclarant ne confirme pas son propre lien
- ✓ Confirmation : invitation expirée ou jeton inconnu
- ✓ Page web de confirmation : question affichée, réponse enregistrée, échappement
- ✓ Alertes : appareil enregistré, oublié à la déconnexion, jeton invalide refusé
- ✓ Alertes : un téléphone passé à un autre compte ne prévient plus l'ancien
- ✓ Alertes : confirmation prévient déclarant et référents, texte sans aucun nom
- ✓ Alertes : une seule marquée lue, puis toutes ; jamais celles d'un autre
- ✓ Passer en privé : motif, fiche masquée aux membres, visible du référent
- ✓ Rattachement à une branche : référent de la branche d'origine, journalisé
- ✓ Relance à 60 jours (date simulée), puis tous les 30 jours, 3 fois maximum
- ✓ Relance : délai lu depuis les paramètres
- ✓ Recette 4 : ZOCLI / ZOKLI, même prénom, même père, années voisines signalés
- ✓ Doublons : poids du paramètre « prénom requis »
- ✓ Doublons : réservés aux référents, à leur branche, sans conflit
- ✓ Recette 5 : fusion puis annulation restituent exactement tout (ligne à ligne)
- ✓ Fusion : parents différents refusés, rien n'est modifié
- ✓ Fusion : plus récente d'abord à l'annulation
- ✓ Une paire déclarée distincte n'est plus jamais proposée
- ✓ Journal : UPDATE et DELETE refusés par la base
- ✓ Journal : chaque type d'action est tracé (qui, quoi, fiche, quand)
- ✓ Polygamie : enfants d'un père regroupés par mère, dans l'ordre du rang
- ✓ Arbre membre : liens validés seulement, sans téléphone ni fiche privée
- ✓ Arbre référent : provisoires visibles et distingués, privés de sa branche inclus
- ✓ Arbre référent d'une autre branche : voit les provisoires, pas les situations privées
- ✓ Arbre : charge par niveaux (profondeur bornée, a_suite signalé)
- ✓ Paramètre arbre_visible_membres : membre refusé, référent autorisé
- ✓ Paramètre generations_lignee_feminine : limite la descendance par les filles
- ✓ Fiche famille : mêmes règles que l'arbre, selon le rôle
- ✓ Stats : par branche et par statut, limitées à la branche du référent
- ✓ Journal : référent limité à sa branche, admin complet, membre refusé
- ✓ GEDCOM : en-tête, dates approximatives, polygamie, TRLR
- ✓ Recette 8 (partie) : GEDCOM limité aux liens validés
- ✓ Recette 8 : le fichier GEDCOM s'ouvre dans Gramps sans erreur
- ✓ GEDCOM : date complète seulement pour une personne décédée
- ✓ CSV : BOM UTF-8, point-virgule, téléphone conservé, formule neutralisée
- ✓ Exports réservés à l'admin et journalisés
- ✓ Recette 6 : balayage de toutes les routes, un membre n'obtient ni téléphone ni fiche privée
- ✓ Recette 6 : routes d'administration et de référent refusées au membre
- ✓ Un référent n'accède pas aux routes d'administration
- ✓ Branches, rôles, variantes et paramètres pilotés sans redéploiement
- ✓ Sauvegarde (mysqldump) puis restauration complète à l'identique
- ✓ Sauvegarde (repli PHP) puis restauration complète à l'identique
- ✓ Sauvegarde : rotation à 7 quotidiennes et 4 hebdomadaires, nom de fichier contrôlé
- ✓ Tâches : verrou contre deux exécutions simultanées, pseudo-cron
- ✓ Admin : mot de passe faux refusé, sans page protégée sans session
- ✓ Admin : connexion en deux facteurs, en-têtes de sécurité, cookie durci
- ✓ Admin : une requête POST sans jeton CSRF est refusée
- ✓ Admin : toutes les pages répondent
- ✓ Admin : saisie du fondateur et d'une branche, échappement HTML
- ✓ Admin : paramètres, rôles, tâches et sauvegardes par le panneau
- ✓ Racine du backend : redirige vers l'administration
- ✓ Admin : accès perdu rétabli, compte retiré réactivé
- ✓ Panneau : formulaires CSRF, boutons de sortie confirmés, pagination
- ✓ Paramètres : chaque réglage de la page est lu et borné
- ✓ Connexion : page unique, sans création de compte ni accès référent
- ✓ Droits : seul l'administrateur entre, ni référent ni membre
- ✓ Administrateur unique : personne d'autre ne peut le devenir
- ✓ Admin web : valide un lien, conflit affiché
- ✓ Admin : annulation d'une fusion depuis le panneau
