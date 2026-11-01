<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\PersonneRepository;
use App\Repositories\UnionRepository;
use App\Serializers\PersonneSerializer;
use App\Services\ArbreService;
use App\Services\FoyerService;
use App\Services\InscriptionService;
use App\Services\JournalService;
use App\Services\PermissionService;
use App\Services\PushService;

final class PersonneController
{
    /** Suit les fusions : l'ancien identifiant mène à la fiche conservée. */
    private static function charger(int $id): array
    {
        $p = PersonneRepository::trouver($id);
        while ($p !== null && $p['fusionnee_dans_id'] !== null) {
            $p = PersonneRepository::trouver((int) $p['fusionnee_dans_id']);
        }
        if ($p === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        return $p;
    }

    private static function brancheDe(array $p): ?int
    {
        return $p['branche_id'] === null ? null : (int) $p['branche_id'];
    }

    public static function voir(Request $r): void
    {
        $p = self::charger((int) $r->params['id']);
        $s = PersonneSerializer::selonRole($p, $r->user);
        if ($s === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        $estLui = (int) $p['id'] === (int) $r->user['personne_id'];
        if ($p['est_privee'] && !$estLui) {
            JournalService::ecrire((int) $r->user['id'], 'personne.consultation_privee', 'personne', (int) $p['id'], self::brancheDe($p));
        }
        Response::ok($s);
    }

    private static function peutCorriger(array $u, array $p): bool
    {
        if ((int) $p['id'] === (int) $u['personne_id'] || PermissionService::couvreBranche($u, self::brancheDe($p))) {
            return true;
        }
        return Database::value(
            "SELECT id FROM declarations_tiers WHERE personne_id = ? AND declarant_utilisateur_id = ? AND type IN ('enfant_mineur','parent_decede')",
            [$p['id'], $u['id']]
        ) !== null;
    }

    public static function modifier(Request $r): void
    {
        $p = self::charger((int) $r->params['id']);
        if (!self::peutCorriger($r->user, $p)) {
            throw new ApiException(403, 'interdit', 'Vous ne pouvez pas modifier cette fiche.');
        }
        $b = $r->body;
        $champs = [];
        if (array_intersect(['annee_naissance', 'mois_naissance', 'jour_naissance'], array_keys($b)) !== []) {
            // La date se corrige d'un bloc : ce qui n'est pas envoyé garde sa valeur.
            $champs = Validator::dateNaissance($b + array_intersect_key($p, array_flip(['annee_naissance', 'mois_naissance', 'jour_naissance'])));
        }
        foreach (['sexe', 'precision_naissance', 'lieu_residence', 'est_decede', 'annee_deces', 'precision_deces', 'email'] as $c) {
            if (!array_key_exists($c, $b)) {
                continue;
            }
            $champs[$c] = match ($c) {
                'sexe' => Validator::choix($b, $c, ['M', 'F', 'I']),
                'annee_deces' => Validator::annee($b, $c),
                'precision_naissance', 'precision_deces' => Validator::choix($b, $c, ['exacte', 'environ', 'decennie', 'inconnue']),
                'lieu_residence' => Validator::texte($b, $c, 160, false),
                'est_decede' => Validator::booleen($b, $c) ? 1 : 0,
                'email' => Validator::email($b, $c, false),
            };
        }
        if (isset($champs['annee_naissance']) === false && array_key_exists('annee_naissance', $champs)) {
            $champs['precision_naissance'] = 'inconnue';
        } elseif (($champs['jour_naissance'] ?? null) !== null) {
            $champs['precision_naissance'] = 'exacte';
        }
        Database::tx(static function () use ($champs, $b, $p, $r): void {
            if ($champs !== []) {
                Database::update('personnes', $champs, 'id = ?', [$p['id']]);
            }
            if (isset($b['nom_famille']) || isset($b['prenoms'])) {
                $autres = array_filter($p['noms'], static fn(array $n): bool => !in_array($n['type'], ['nom_famille', 'naissance'], true));
                $noms = InscriptionService::identite([
                    'nom_famille' => $b['nom_famille'] ?? self::nomDeType($p, 'nom_famille'),
                    'prenoms' => $b['prenoms'] ?? self::nomDeType($p, 'naissance'),
                    'autres_noms' => $b['autres_noms'] ?? array_values($autres),
                ])['noms'];
                PersonneRepository::remplacerNoms((int) $p['id'], $noms);
            }
            JournalService::ecrire((int) $r->user['id'], 'personne.modification', 'personne', (int) $p['id'], self::brancheDe($p), ['champs' => array_keys($champs)]);
        });
        Response::ok(PersonneSerializer::selonRole(PersonneRepository::trouver((int) $p['id']), $r->user));
    }

    private static function nomDeType(array $p, string $type): string
    {
        foreach ($p['noms'] as $n) {
            if ($n['type'] === $type) {
                return $n['valeur'];
            }
        }
        return '';
    }

    public static function retrait(Request $r): void
    {
        $p = self::charger((int) $r->params['id']);
        if (!self::peutCorriger($r->user, $p)) {
            throw new ApiException(403, 'interdit', 'Vous ne pouvez pas retirer cette fiche.');
        }
        if (!Validator::booleen($r->body, 'confirmation')) {
            Validator::echec('confirmation', 'Confirmation requise.');
        }
        $estLui = (int) $p['id'] === (int) $r->user['personne_id'];
        Database::tx(static function () use ($p, $r, $estLui): void {
            Database::run(
                "UPDATE personnes SET statut_fiche = 'retiree', telephone_whatsapp = NULL, email = NULL WHERE id = ?",
                [$p['id']]
            );
            Database::run('UPDATE consentements SET retire_le = UTC_TIMESTAMP() WHERE personne_id = ? AND retire_le IS NULL', [$p['id']]);
            JournalService::ecrire((int) $r->user['id'], 'personne.retrait', 'personne', (int) $p['id'], self::brancheDe($p));
            if ($estLui) {
                Database::run('UPDATE sessions_api SET revoque_le = UTC_TIMESTAMP() WHERE utilisateur_id = ? AND revoque_le IS NULL', [$r->user['id']]);
                Database::run('UPDATE utilisateurs SET actif = 0 WHERE id = ?', [$r->user['id']]);
                PushService::oublier((int) $r->user['id']);
            }
        });
        Response::ok(['retiree' => true]);
    }

    public static function recherche(Request $r): void
    {
        RateLimiter::verifier('recherche', 'u' . $r->user['id'], RATE_RECHERCHE);
        $q = trim((string) ($r->query['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Validator::echec('q', 'Saisissez au moins deux lettres.');
        }
        [, $parPage, $offset] = $r->page();
        $u = $r->user;
        $branches = PermissionService::estAdmin($u) ? null : (PermissionService::estReferent($u) ? PermissionService::branchesReferent($u) : []);
        $ids = PersonneRepository::rechercher($q, $branches, $parPage, $offset);
        $res = [];
        foreach (PersonneRepository::parIds($ids) as $p) {
            $s = PersonneSerializer::selonRole($p, $u);
            if ($s !== null) {
                $res[] = $s;
            }
        }
        Response::ok($res);
    }

    public static function epouses(Request $r): void
    {
        $p = self::charger((int) $r->params['id']);
        if (PersonneSerializer::selonRole($p, $r->user) === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        $unions = UnionRepository::epouses((int) $p['id']);
        $fiches = PersonneRepository::parIds(array_column($unions, 'epouse_id'));
        $res = [];
        foreach ($unions as $l) {
            $s = isset($fiches[$l['epouse_id']]) ? PersonneSerializer::selonRole($fiches[$l['epouse_id']], $r->user) : null;
            if ($s !== null && ($l['statut'] !== 'prive' || PermissionService::couvreBranche($r->user, self::brancheDe($p)))) {
                $res[] = ['union_id' => (int) $l['id'], 'rang_epouse' => (int) $l['rang_epouse'], 'statut' => $l['statut'], 'personne' => $s];
            }
        }
        Response::ok($res);
    }

    public static function ordonnerEpouses(Request $r): void
    {
        PermissionService::exigerReferent($r->user);
        FoyerService::ordonner($r->user, (int) $r->params['id'], (array) ($r->body['ordre'] ?? []));
        Response::ok(['ordonne' => true]);
    }

    /** Parents, conjoints et enfants, avec les mêmes règles de visibilité que l'arbre. */
    public static function famille(Request $r): void
    {
        $p = self::charger((int) $r->params['id']);
        if (PersonneSerializer::selonRole($p, $r->user) === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        Response::ok(ArbreService::famille($p, $r->user));
    }

    public static function creerUnion(Request $r): void
    {
        $b = $r->body;
        $epoux = self::charger(Validator::entier($b, 'epoux_id', true, 1));
        $epouse = self::charger(Validator::entier($b, 'epouse_id', true, 1));
        if (PersonneSerializer::selonRole($epoux, $r->user) === null || PersonneSerializer::selonRole($epouse, $r->user) === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        if ($epoux['sexe'] === 'F' || $epouse['sexe'] === 'M' || $epoux['id'] === $epouse['id']) {
            Validator::echec('epoux_id', 'Union invalide.');
        }
        $id = UnionRepository::creerSiAbsente(
            (int) $epoux['id'],
            (int) $epouse['id'],
            Validator::annee($b, 'annee_union'),
            Validator::choix($b, 'precision_union', ['exacte', 'environ', 'decennie', 'inconnue'], 'inconnue')
        );
        JournalService::ecrire((int) $r->user['id'], 'union.creation', 'union', $id, self::brancheDe($epoux));
        Response::ok(['union_id' => $id], 201);
    }
}
