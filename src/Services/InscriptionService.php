<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Repositories\LienRepository;
use App\Repositories\PersonneRepository;
use App\Repositories\UnionRepository;
use App\Serializers\PersonneSerializer;

final class InscriptionService
{
    private const PRECISIONS = ['exacte', 'environ', 'decennie', 'inconnue'];
    private const TYPES_NOMS = ['vodoun', 'bapteme', 'surnom', 'naissance'];
    private const TYPES_TIERS = ['enfant_mineur', 'parent_decede', 'aine', 'autre'];

    public static function pourSoi(array $u, array $b): array
    {
        $uuid = Validator::uuid($b, 'client_uuid');
        return self::rejeu($uuid, $u) ?? self::idempotent($uuid, $u, static function () use ($u, $b): array {
            if ($u['personne_id'] !== null) {
                throw new ApiException(409, 'deja_inscrit', 'Vous avez déjà une fiche.');
            }
            self::exigerConsentement($b);
            $branche = self::branche($b);
            $revendiquee = Validator::entier($b, 'personne_id', false, 1);
            if ($revendiquee !== null) {
                $id = self::revendiquer($revendiquee, $u);
                $branche = (int) (Database::value('SELECT branche_id FROM personnes WHERE id = ?', [$id]) ?? $branche);
            } else {
                // Une personne n'existe qu'une fois : sa fiche a pu être créée par un enfant ou un relais.
                $identite = self::identite($b['identite'] ?? null);
                self::exigerRapprochement('identite', $b['identite'], $branche, $u);
                $identite['champs'] += [
                    'branche_id' => $branche,
                    'telephone_whatsapp' => $u['telephone_whatsapp'],
                    'email' => $u['email'],
                ];
                $id = PersonneRepository::creer($identite['champs'], $identite['noms'], (int) $u['id']);
                JournalService::ecrire((int) $u['id'], 'personne.creation', 'personne', $id, $branche);
            }
            Database::run('UPDATE utilisateurs SET personne_id = ? WHERE id = ?', [$id, $u['id']]);
            self::consentement($id, (int) $u['id'], $branche);
            return ['personne_id' => $id] + self::parents($id, $b, $branche, $u);
        });
    }

    /**
     * « C'est moi » : l'inscrit reprend une fiche existante plutôt que d'en créer une seconde.
     * Seulement une fiche sans compte, jamais une fiche portant le numéro de quelqu'un d'autre ;
     * les référents sont prévenus pour contrôler.
     */
    private static function revendiquer(int $id, array $u): int
    {
        $p = PersonneRepository::trouver($id);
        if ($p === null || PersonneSerializer::selonRole($p, $u) === null || $p['est_decede']) {
            Validator::echec('personne_id', 'Fiche introuvable.');
        }
        $prise = Database::value('SELECT id FROM utilisateurs WHERE personne_id = ?', [$id]) !== null;
        if ($prise || ($p['telephone_whatsapp'] !== null && $p['telephone_whatsapp'] !== $u['telephone_whatsapp'])) {
            throw new ApiException(409, 'fiche_prise', 'Cette fiche est déjà liée à un autre numéro. Contactez le référent de votre branche.');
        }
        Database::update('personnes', ['telephone_whatsapp' => $u['telephone_whatsapp'], 'email' => $p['email'] ?? $u['email']], 'id = ?', [$id]);
        $branche = $p['branche_id'] === null ? null : (int) $p['branche_id'];
        JournalService::ecrire((int) $u['id'], 'personne.revendication', 'personne', $id, $branche);
        foreach (Database::ids('SELECT utilisateur_id FROM referents_branche WHERE branche_id = ?', [(int) $branche]) as $ref) {
            NotificationService::notifier($ref, 'revendication', "Un membre s'est reconnu dans la fiche de {$p['nom_complet']} : vérifiez qu'il s'agit bien de lui.", null, false);
        }
        return $id;
    }

    public static function pourTiers(array $u, array $b): array
    {
        $uuid = Validator::uuid($b, 'client_uuid');
        return self::rejeu($uuid, $u) ?? self::idempotent($uuid, $u, static function () use ($u, $b): array {
            $type = Validator::choix($b, 'type', self::TYPES_TIERS);
            $present = Validator::booleen($b, 'present_lors_saisie');
            self::exigerConsentement($b);
            $branche = self::branche($b);
            self::verifierDroitTiers($u, $type, $branche, $present);

            $existant = Validator::entier($b, 'personne_id', false);
            if ($existant !== null) {
                $id = self::ficheExistante($type, $existant, $u);
            } else {
                $identite = self::identite($b['identite'] ?? null);
                self::verifierAge($type, $identite['champs']['annee_naissance'] ?? null);
                if ($type === 'parent_decede') {
                    $identite['champs']['est_decede'] = 1;
                }
                self::exigerRapprochement('identite', $b['identite'], $branche, $u);
                $id = PersonneRepository::creer($identite['champs'] + ['branche_id' => $branche], $identite['noms'], (int) $u['id']);
                JournalService::ecrire((int) $u['id'], 'personne.creation', 'personne', $id, $branche, ['tiers' => $type]);
            }
            $declaration = Database::insert('declarations_tiers', [
                'personne_id' => $id,
                'declarant_utilisateur_id' => $u['id'],
                'type' => $type,
                'present_lors_saisie' => $present ? 1 : 0,
                'note' => Validator::texte($b, 'note', 500, false),
            ]);
            self::consentement($id, (int) $u['id'], $branche);
            $liens = self::parents($id, self::avecDeclarant($b, $type, $u), $branche, $u);
            if ($type === 'parent_decede') {
                $liens['lien_declarant_id'] = self::relierDeclarant($id, $u);
            }
            return ['personne_id' => $id, 'declaration_id' => $declaration] + $liens;
        });
    }

    private static function rejeu(string $uuid, array $u): ?array
    {
        $ligne = Database::one('SELECT utilisateur_id, resultat FROM file_sync WHERE client_uuid = ?', [$uuid]);
        if ($ligne === null) {
            return null;
        }
        if ((int) $ligne['utilisateur_id'] !== (int) $u['id']) {
            throw new ApiException(409, 'uuid_utilise', 'Identifiant d\'envoi déjà utilisé.');
        }
        return json_decode($ligne['resultat'], true) + ['rejoue' => true];
    }

    private static function idempotent(string $uuid, array $u, callable $traitement): array
    {
        try {
            return Database::tx(static function () use ($uuid, $u, $traitement): array {
                $resultat = $traitement();
                Database::insert('file_sync', [
                    'client_uuid' => $uuid,
                    'utilisateur_id' => $u['id'],
                    'recu_le' => gmdate('Y-m-d H:i:s'),
                    'resultat' => json_encode($resultat),
                ]);
                return $resultat;
            });
        } catch (\PDOException $e) {
            // Deux envois simultanés du même uuid : le second est annulé puis rejoué.
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'file_sync')) {
                return self::rejeu($uuid, $u) ?? throw $e;
            }
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_lien_actif')) {
                throw new ApiException(409, 'lien_existant', 'Un lien existe déjà pour ce parent.');
            }
            throw $e;
        }
    }

    private static function exigerConsentement(array $b): void
    {
        if (!Validator::booleen($b, 'consentement')) {
            Validator::echec('consentement', 'Le consentement est obligatoire.');
        }
    }

    private static function consentement(int $personneId, int $utilisateurId, int $branche): void
    {
        JournalService::ecrire($utilisateurId, 'consentement.accepte', 'personne', $personneId, $branche, ['version' => CONSENTEMENT_VERSION]);
        Database::insert('consentements', [
            'personne_id' => $personneId,
            'utilisateur_id' => $utilisateurId,
            'version_texte' => CONSENTEMENT_VERSION,
            'accepte_le' => gmdate('Y-m-d H:i:s'),
            'ip' => Request::ip(),
        ]);
    }

    private static function branche(array $b): int
    {
        $id = Validator::entier($b, 'branche_id', true, 1);
        if (Database::value('SELECT id FROM branches WHERE id = ? AND actif = 1', [$id]) === null) {
            Validator::echec('branche_id', 'Branche inconnue.');
        }
        return $id;
    }

    /** @return array{champs:array, noms:array} */
    public static function identite(mixed $d): array
    {
        if (!is_array($d)) {
            Validator::echec('identite', 'Identité manquante.');
        }
        $noms = [
            ['type' => 'nom_famille', 'valeur' => Validator::texte($d, 'nom_famille')],
            ['type' => 'naissance', 'valeur' => Validator::texte($d, 'prenoms')],
        ];
        foreach (($d['autres_noms'] ?? []) as $n) {
            if (is_array($n) && trim((string) ($n['valeur'] ?? '')) !== '') {
                $noms[] = ['type' => Validator::choix($n, 'type', self::TYPES_NOMS), 'valeur' => Validator::texte($n, 'valeur')];
            }
        }
        $date = Validator::dateNaissance($d);
        $annee = $date['annee_naissance'];
        $precision = Validator::choix($d, 'precision_naissance', self::PRECISIONS, $annee === null ? 'inconnue' : 'environ');
        if ($annee === null) {
            $precision = 'inconnue';
        } elseif ($date['jour_naissance'] !== null) {
            $precision = 'exacte';
        }
        $champs = [
            'sexe' => Validator::choix($d, 'sexe', ['M', 'F', 'I'], 'I'),
            ...$date,
            'precision_naissance' => $precision,
            'lieu_residence' => Validator::texte($d, 'lieu_residence', 160, false),
            'est_decede' => Validator::booleen($d, 'est_decede') ? 1 : 0,
        ];
        return ['champs' => $champs, 'noms' => $noms];
    }

    /** Critère de rapprochement à partir d'une saisie (prénoms, autres noms, année, parents). */
    public static function critere(array $d, ?int $branche): array
    {
        $texte = (string) ($d['prenoms'] ?? '');
        foreach (($d['autres_noms'] ?? []) as $n) {
            $texte .= ' ' . (is_array($n) ? (string) ($n['valeur'] ?? '') : '');
        }
        $annee = isset($d['annee_naissance']) && is_numeric($d['annee_naissance']) ? (int) $d['annee_naissance'] : null;
        return [
            'tokens' => array_values(array_unique(NormalisationService::tokens($texte))),
            'branche_id' => $branche,
            'annee' => ($d['precision_naissance'] ?? '') === 'inconnue' ? null : $annee,
            'pere_id' => isset($d['pere_id']) ? (int) $d['pere_id'] : null,
            'mere_id' => isset($d['mere_id']) ? (int) $d['mere_id'] : null,
            'pere_tokens' => NormalisationService::tokens((string) ($d['pere_nom'] ?? '')),
            'mere_tokens' => NormalisationService::tokens((string) ($d['mere_nom'] ?? '')),
            'exclure_id' => isset($d['exclure_id']) ? (int) $d['exclure_id'] : null,
        ];
    }

    /** @return array[] candidats sérialisés selon le rôle ; les fiches masquées sont anonymisées */
    public static function candidatsSerialises(array $crit, array $u): array
    {
        $liste = MatchingService::candidats($crit);
        $fiches = PersonneRepository::parIds(array_column($liste, 'personne_id'));
        $res = [];
        $anonyme = false;
        foreach ($liste as $c) {
            $p = $fiches[$c['personne_id']] ?? null;
            $s = $p === null ? null : PersonneSerializer::selonRole($p, $u);
            if ($s === null) {
                $anonyme = true;
                continue;
            }
            $res[] = ['personne' => $s, 'score' => $c['score'], 'filiation' => self::filiation($c['personne_id'])];
        }
        if ($anonyme) {
            $res[] = PersonneSerializer::anonyme();
        }
        return $res;
    }

    /** Nom du père validé et public, pour aider l'inscrit à reconnaître la fiche. */
    private static function filiation(int $personneId): ?string
    {
        $pere = Database::value(
            "SELECT p.id FROM liens_parente l JOIN personnes p ON p.id = l.parent_id
             WHERE l.enfant_id = ? AND l.role_parent = 'pere' AND l.statut = 'valide' AND " . PersonneRepository::VISIBLE_MEMBRE,
            [$personneId]
        );
        return $pere === null ? null : (PersonneRepository::trouver((int) $pere)['nom_complet'] ?? null);
    }

    /** Une nouvelle fiche ne se crée qu'après que l'inscrit a vu les fiches ressemblantes. */
    private static function exigerRapprochement(string $cible, array $saisie, ?int $branche, array $u): void
    {
        if (Validator::booleen($saisie, 'rapprochement_vu')) {
            return;
        }
        $candidats = self::candidatsSerialises(self::critere($saisie, $branche), $u);
        if ($candidats !== []) {
            throw new ApiException(409, 'rapprochement_requis', 'Des fiches ressemblantes existent : choisissez-en une ou confirmez une nouvelle fiche.', ['cible' => $cible, 'candidats' => $candidats]);
        }
    }

    /** @return array{pere_lien_id:?int, mere_lien_id:?int, pere_id:?int, mere_id:?int} */
    private static function parents(int $enfantId, array $b, int $branche, array $u): array
    {
        $pereId = self::parent('pere', $b['pere'] ?? null, $branche, $u);
        $mereId = self::parent('mere', $b['mere'] ?? null, $branche, $u);
        $res = ['pere_lien_id' => null, 'mere_lien_id' => null, 'pere_id' => $pereId, 'mere_id' => $mereId];
        foreach (['pere' => $pereId, 'mere' => $mereId] as $role => $parentId) {
            if ($parentId === null) {
                continue;
            }
            self::verifierPasDeBoucle($parentId, $enfantId);
            $existant = LienRepository::actifPourRole($enfantId, $role);
            if ($existant !== null && (int) $existant['parent_id'] === $parentId) {
                $res[$role . '_lien_id'] = (int) $existant['id'];
                continue;
            }
            $lien = LienRepository::creer($parentId, $enfantId, $role, (int) $u['id']);
            JournalService::ecrire((int) $u['id'], 'lien.declaration', 'lien', $lien, $branche, ['role' => $role]);
            $res[$role . '_lien_id'] = $lien;
        }
        if ($pereId !== null && $mereId !== null) {
            $union = UnionRepository::creerSiAbsente($pereId, $mereId);
            JournalService::ecrire((int) $u['id'], 'union.declaration', 'union', $union, $branche);
        }
        return $res;
    }

    private static function parent(string $role, mixed $spec, int $branche, array $u): ?int
    {
        if (!is_array($spec) || $spec === []) {
            return null;
        }
        $sexeAttendu = $role === 'pere' ? 'M' : 'F';
        if (isset($spec['personne_id'])) {
            $p = PersonneRepository::trouver((int) $spec['personne_id']);
            while ($p !== null && $p['fusionnee_dans_id'] !== null) {
                $p = PersonneRepository::trouver((int) $p['fusionnee_dans_id']);
            }
            if ($p === null || PersonneSerializer::selonRole($p, $u) === null) {
                Validator::echec($role, 'Fiche introuvable.');
            }
            if ($p['sexe'] !== 'I' && $p['sexe'] !== $sexeAttendu) {
                Validator::echec($role, 'Cette fiche ne correspond pas au rôle choisi.');
            }
            return (int) $p['id'];
        }
        if (!is_array($spec['nouvelle'] ?? null)) {
            Validator::echec($role, 'Parent invalide.');
        }
        $saisie = ['sexe' => $sexeAttendu] + $spec['nouvelle'];
        $saisie['sexe'] = $sexeAttendu;
        $identite = self::identite($saisie);
        self::exigerRapprochement($role, $saisie, $branche, $u);
        $id = PersonneRepository::creer($identite['champs'] + ['branche_id' => $branche], $identite['noms'], (int) $u['id']);
        JournalService::ecrire((int) $u['id'], 'personne.creation', 'personne', $id, $branche, ['role' => $role]);
        return $id;
    }

    private static function verifierPasDeBoucle(int $parentId, int $enfantId): void
    {
        if ($parentId === $enfantId || LienRepository::estDescendant($parentId, $enfantId)) {
            throw new ApiException(409, 'boucle_parente', 'Ce lien créerait une boucle dans l\'arbre.');
        }
    }

    private static function verifierDroitTiers(array $u, string $type, int $branche, bool $present): void
    {
        if (in_array($type, ['enfant_mineur', 'parent_decede'], true)) {
            if ($u['personne_id'] === null) {
                throw new ApiException(409, 'fiche_requise', 'Inscrivez-vous d\'abord vous-même.');
            }
            return;
        }
        $estRelais = in_array($branche, PermissionService::branchesRelais($u), true) || PermissionService::couvreBranche($u, $branche);
        if (!$estRelais) {
            throw new ApiException(403, 'interdit', 'Seul un relais de cette branche peut inscrire un aîné.');
        }
        if ($type === 'aine' && !$present) {
            Validator::echec('present_lors_saisie', 'La personne doit être présente lors de la saisie.');
        }
    }

    private static function verifierAge(string $type, ?int $annee): void
    {
        if ($type === 'enfant_mineur' && $annee !== null && (int) gmdate('Y') - $annee > 18) {
            Validator::echec('annee_naissance', 'Cette personne n\'est plus mineure.');
        }
    }

    private static function ficheExistante(string $type, int $id, array $u): int
    {
        if ($type !== 'parent_decede') {
            Validator::echec('personne_id', 'Choix réservé à la déclaration d\'un parent décédé.');
        }
        $p = PersonneRepository::trouver($id);
        if ($p === null || PersonneSerializer::selonRole($p, $u) === null) {
            Validator::echec('personne_id', 'Fiche introuvable.');
        }
        return $id;
    }

    /** L'enfant mineur est rattaché à son déclarant, sauf s'il est déjà indiqué comme parent. */
    private static function avecDeclarant(array $b, string $type, array $u): array
    {
        if ($type !== 'enfant_mineur' || !Validator::booleen($b, 'relier_a_moi', true)) {
            return $b;
        }
        $moi = PersonneRepository::trouver((int) $u['personne_id']);
        if ($moi === null || $moi['sexe'] === 'I') {
            Validator::echec('relier_a_moi', 'Indiquez votre sexe sur votre fiche, ou le parent de l\'enfant.');
        }
        $b[$moi['sexe'] === 'M' ? 'pere' : 'mere'] = ['personne_id' => (int) $moi['id']];
        return $b;
    }

    private static function relierDeclarant(int $parentId, array $u): int
    {
        $enfantId = (int) $u['personne_id'];
        $parent = PersonneRepository::trouver($parentId);
        if ($parent['sexe'] === 'I') {
            Validator::echec('identite', 'Précisez le sexe du parent.');
        }
        $role = $parent['sexe'] === 'M' ? 'pere' : 'mere';
        self::verifierPasDeBoucle($parentId, $enfantId);
        $existant = LienRepository::actifPourRole($enfantId, $role);
        if ($existant !== null) {
            if ((int) $existant['parent_id'] === $parentId) {
                return (int) $existant['id'];
            }
            throw new ApiException(409, 'lien_existant', 'Vous avez déjà déclaré ce parent.');
        }
        $lien = LienRepository::creer($parentId, $enfantId, $role, (int) $u['id']);
        JournalService::ecrire((int) $u['id'], 'lien.declaration', 'lien', $lien, $parent['branche_id'] === null ? null : (int) $parent['branche_id'], ['role' => $role]);
        return $lien;
    }
}
