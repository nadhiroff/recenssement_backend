# Conformité au cahier des charges

Comparaison ligne à ligne de la note « Recensement famille ZOCLI – Note de présentation » avec l'application livrée.

**Statuts :** Conforme · Écart (le cahier n'est pas respecté à ce jour) · À décider (ne relève pas du développement).

## Modules

| Module du cahier | Statut | Réalisation |
|---|---|---|
| Connexion : numéro WhatsApp vérifié par code à usage unique | **Écart** | Identifiant = numéro WhatsApp, code à usage unique, mais **le code part par email**. Voir « Écarts » |
| Inscription en cinq étapes | Conforme | Identité, branche, père, mère, vérification et consentement |
| Inscription d'un tiers avec trace du déclarant | Conforme | Enfant mineur, parent décédé, aîné avec présence attestée |
| Rapprochement, choix par l'inscrit | Conforme | Pour les parents, les tiers et l'inscrit lui-même |
| Confirmation par lien WhatsApp | Conforme | Lien `wa.me`, page sans compte, réponse Oui / Non / Je ne sais pas |
| Espace référent | Conforme | Valider, consulter les aînés, privé, rejeter, rattacher, dans l'app et sur le web |
| Doublons hebdomadaires, fusion ou séparation | Conforme | Tâche planifiée, jamais à chaque requête |
| Arbre par branche, enfants par mère, provisoires distingués | Conforme | Membres : validés. Référents : tout, sauf les situations privées d'une autre branche |
| Administration | Conforme | Branches, rôles, annulation de fusion, exports |
| Suivi : compteurs et journal | Conforme | Dans l'app et sur le web |

## Règles métier

| Règle | Statut | Preuve |
|---|---|---|
| Une personne n'existe qu'une fois, liens séparés | Conforme | Rapprochement avant toute création, reprise de fiche, table `liens_parente` |
| Cycle déclaré, confirmé, validé, plus examen, privé, rejet | Conforme | Machine à états, 409 sinon |
| Seuls les liens validés dans l'arbre des membres | Conforme | Balayage automatique des routes |
| Un référent ne valide jamais ce qui le concerne, lui ou ses enfants | Conforme | 403 et orientation vers l'autre référent ou l'admin |
| Année et précision, aucune date complète exigée | Conforme | Jour et mois facultatifs, ajoutés à la demande du commanditaire |
| Plusieurs noms, tous servent à la recherche | Conforme | Recherche et rapprochement |
| Plusieurs épouses, rang, enfants groupés par mère | Conforme | Test dédié |
| Normalisation, variantes modifiables sans redéploiement | Conforme | Panneau admin |
| Score 30/20/25/15/10, seuils 50/70, paramétrables | Conforme | Table `parametres` |
| Une fusion ne supprime rien, annulation, paire distincte jamais reproposée | Conforme | Comparaison ligne à ligne |
| Journal non modifiable | Conforme | Déclencheurs SQL |
| Relance à 60 jours | Conforme | Date simulée en test |
| Téléphones et fiches privées jamais renvoyés à un membre | Conforme | 284 appels contrôlés |

## Choix techniques et contraintes

| Exigence | Statut | Réalisation |
|---|---|---|
| PWA installable, lien sur WhatsApp | Conforme | PWA plus application mobile issues du même code |
| Pages légères, téléphone d'entrée de gamme | Partiel | 650 Ko compressés. 10 s en 3G à la première visite, 1,4 s ensuite |
| Formulaire rempli hors ligne, envoi au retour du réseau | Conforme | Après une première connexion, en ligne, pour recevoir le code |
| Base relationnelle | Conforme | MySQL / MariaDB, « PostgreSQL ou équivalent » |
| Sauvegarde quotidienne, restauration testée | Partiel | Testée en local. **À refaire sur l'hébergement réel avant l'ouverture** |
| Code de connexion et invitations par WhatsApp | **Écart** | Invitations : oui. Code : email |
| Interface en français, libellés regroupés, audio prévu | Conforme | `src/i18n/fr.json`, clés audio prêtes |
| Consentement horodaté, correction, retrait, accès par rôle | Conforme | |
| Description des traitements pour l'APDP | Conforme | À compléter par la famille |
| GEDCOM 5.5.1 lisible par Gramps, CSV admin | Conforme | Import Gramps réel sans erreur |
| Code remis dans un dépôt détenu par l'administrateur général | **Écart** | Dépôts actuels au nom de nadhiroff, et publics |
| Hors périmètre respecté (photos, documents, messagerie, cotisations, impression) | Conforme | Aucun de ces modules |
| Trois lots, documentation | Conforme | Installation, sauvegarde, guide d'une page |

## Écarts à lever

1. **Code par email au lieu de WhatsApp.** Le cahier demande WhatsApp. Le développement est prêt (`WhatsAppOtpSender`, activé par `OTP_CHANNEL`) mais il faut un compte WhatsApp Business ou un prestataire, et un coût par message à décider. Tant que l'email est utilisé, le numéro n'est pas prouvé : quelqu'un peut créer un compte sur un numéro libre avec son propre email.
2. **Propriété du dépôt.** À transférer à l'administrateur général et à passer en privé. Les fichiers de la note restent dans l'historique du dépôt frontend public tant que celui-ci n'est pas réécrit ou recréé.
3. **Recette non réalisée.** Le cahier accepte un lot quand les scénarios passent sur un Android d'entrée de gamme en 3G. Les tests ont été faits en automatique et sur iPhone, pas sur cet appareil.

## Limite connue

Une personne qui ouvre sans se connecter sa propre invitation peut répondre Oui à la place d'un proche : le lien de confirmation ne prouve pas l'identité de celui qui l'ouvre. Le référent reste le second regard exigé par la règle « aucun lien sur la seule parole d'une personne ». L'envoi d'un code WhatsApp au proche fermerait ce point.

## À décider par le conseil de famille

Le cahier demande que ces questions soient tranchées avant le lot 1. Elles restent ouvertes :

- Descendants par les filles et profondeur : paramètre prêt, sans limite par défaut.
- Adoptions et enfants confiés : champ prévu, sans écran.
- Arbre consultable ou seulement imprimé : paramètre prêt, consultable par défaut.
- Financement du développement, de l'hébergement et des messages WhatsApp.
- Interlocuteur unique du développeur.
- Désignation du fondateur, des branches, de deux référents par branche, de l'administrateur et des relais : le jeu de données actuel est fictif.

## Documents non reçus

La note cite trois documents qui n'ont pas été fournis : **le prototype, le modèle de données et la maquette des écrans**. Le prototype est la référence fonctionnelle « écran par écran » et le modèle détaille chaque table. La conformité ci-dessus ne porte donc que sur la note : le modèle de données a été reconstruit à partir des règles, et il faudra le comparer à l'original.
