# API

Préfixe : `/api/v1` (ou `/api/index.php/v1`, ou `/api/index.php?route=/v1/...` sans mod_rewrite).

Réponses : `{ "ok": true, "data": ... }` ou `{ "ok": false, "error": { "code", "message", ... } }`. Authentification : `Authorization: Bearer <jeton>`. Pagination : `?page=&per_page=`. Codes : 400, 401, 403, 404, 409, 410, 422, 429.

| Méthode et route | Accès | Rôle |
|---|---|---|
| POST `/auth/otp/request` | public | `{telephone, email?}` envoie un code |
| POST `/auth/otp/verify` | public | `{telephone, code}` → `{token, utilisateur}` |
| POST `/auth/logout` · GET `/me` | connecté | déconnexion · profil |
| POST `/me/email` · POST `/me/email/confirmer` | connecté | changer l'email de connexion, avec code reçu sur la nouvelle adresse |
| GET `/branches` | connecté | branches actives |
| POST `/inscriptions` | connecté | parcours complet, idempotent par `client_uuid` ; `personne_id` pour reprendre sa fiche existante |
| POST `/inscriptions/tiers` | connecté | `type` : `enfant_mineur`, `parent_decede`, `aine`, `autre` |
| POST `/rapprochement/candidats` | connecté | fiches au-dessus du seuil |
| GET `/personnes/recherche?q=` | connecté | recherche sur tous les noms |
| GET, PATCH `/personnes/{id}` | selon rôle | voir, corriger |
| POST `/personnes/{id}/retrait` | propriétaire | `{confirmation: true}` |
| POST `/personnes/{id}/branche` | référent | `{branche_id}` |
| GET `/personnes/{id}/epouses` · POST `/unions` | connecté | foyers |
| GET `/personnes/{id}/famille` | connecté | parents, conjoints, enfants, selon le rôle |
| GET `/mes-liens` · GET `/liens/{id}` | connecté | ses liens, détail |
| POST `/liens/{id}/invitations` | déclarant | `{telephone?}` → `{url, whatsapp_url}` |
| GET, POST `/confirmations/{token}` | public | `{reponse: oui, non, ne_sait_pas}` |
| GET `/referent/file?statut=&branche=` | référent | file de la branche |
| POST `/liens/{id}/valider`, `/examen` | référent | décision |
| POST `/liens/{id}/prive`, `/rejeter` | référent | `{motif}` obligatoire |
| GET `/doublons?statut=` | référent | paires |
| POST `/doublons/{id}/fusionner` | référent | `{personne_conservee_id}` |
| POST `/doublons/{id}/distinct` | référent | paire écartée pour toujours |
| POST `/fusions/{id}/annuler` | admin | restitution exacte |
| GET `/arbre?branche=&racine=&profondeur=` | connecté | 1 à 4 niveaux, enfants groupés par foyer |
| GET `/stats` · GET `/journal` | référent | sa branche ; admin : tout |
| GET `/notifications` · POST `/notifications/{id}/lu` · POST `/notifications/lu` | connecté | alertes |
| POST `/me/appareils` | connecté | `{token, plateforme}` déclare le téléphone pour les alertes système |
| GET `/export/gedcom` · GET `/export/csv` | admin | exports |
| GET `/taches` · POST `/taches/{nom}/lancer` | admin | `detect_doublons`, `relances`, `backup` |
| POST `/admin/branches` · PATCH `/admin/branches/{id}` | admin | branches |
| GET `/admin/utilisateurs` · POST `/admin/utilisateurs/{id}/role` | admin | rôles |
| GET, POST `/admin/variantes` · PATCH, DELETE `/admin/variantes/{id}` · POST `/admin/variantes/recalculer` | admin | variantes |
| GET, PATCH `/admin/parametres` | admin | poids et seuils |

Exemple d'inscription :

```json
POST /inscriptions
{
  "client_uuid": "6f1c2c4e-5b1a-4f7e-9a3d-2b7d1c0e8f11",
  "identite": {"nom_famille": "ZOCLI", "prenoms": "Afi", "sexe": "F", "annee_naissance": 1995, "precision_naissance": "environ"},
  "branche_id": 1,
  "pere": {"personne_id": 5},
  "mere": {"nouvelle": {"nom_famille": "AHO", "prenoms": "Nadège", "rapprochement_vu": true}},
  "consentement": true
}
```

Si une fiche ressemblante existe et que `rapprochement_vu` est absent : réponse 409 `rapprochement_requis`, avec `cible` et `candidats`, et rien n'est créé. Le numéro béninois s'écrit `+22901` suivi de 8 chiffres ; un ancien numéro à 8 chiffres est complété automatiquement.
