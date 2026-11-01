<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Serializers\PersonneSerializer;

/** Arbre chargé par niveaux : on ne sert jamais plus que la profondeur demandée. */
final class ArbreService
{
    private const PROFONDEUR_MAX = 4;

    public static function racineDe(?int $branche, ?int $racine): int
    {
        if ($racine !== null) {
            return $racine;
        }
        $id = $branche !== null
            ? Database::value('SELECT ancetre_personne_id FROM branches WHERE id = ?', [$branche])
            : null;
        $id ??= Database::value('SELECT id FROM personnes WHERE est_fondateur = 1 AND fusionnee_dans_id IS NULL LIMIT 1');
        if ($id === null) {
            throw new ApiException(404, 'arbre_vide', 'L\'ancêtre fondateur n\'est pas encore enregistré.');
        }
        return (int) $id;
    }

    public static function construire(array $u, ?int $branche, ?int $racine, int $profondeur): array
    {
        if (!PermissionService::estReferent($u) && ParametreService::entier('arbre_visible_membres', 1) !== 1) {
            throw new ApiException(403, 'arbre_ferme', 'L\'arbre n\'est pas consultable dans l\'application.');
        }
        $id = self::racineDe($branche, $racine);
        $p = PersonneRepository::trouver($id);
        while ($p !== null && $p['fusionnee_dans_id'] !== null) {
            $p = PersonneRepository::trouver((int) $p['fusionnee_dans_id']);
        }
        $s = $p === null ? null : PersonneSerializer::selonRole($p, $u);
        if ($s === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        $profondeur = min(self::PROFONDEUR_MAX, max(1, $profondeur));
        $noeud = ['personne' => $s, 'statut_lien' => null];
        $niveau = [&$noeud];
        self::developper($niveau, $u, $profondeur, [(int) $p['id'] => 0]);
        return $noeud;
    }

    /** Un lien s'affiche s'il est validé et public, ou si le lecteur est référent de la branche de l'enfant. */
    private static function visible(array $lien, array $fiche, array $u): bool
    {
        if ($lien['statut'] === 'valide' && !PersonneSerializer::estMasquee($fiche)) {
            return true;
        }
        return PermissionService::couvreBranche($u, $fiche['branche_id'] === null ? null : (int) $fiche['branche_id'])
            && $fiche['statut_fiche'] === 'active' && $fiche['fusionnee_dans_id'] === null;
    }

    /**
     * Remplit un niveau de l'arbre puis passe au suivant, en une requête par génération.
     * @param array<int, int> $lignee par personne, générations écoulées depuis une fille de la lignée
     */
    private static function developper(array &$noeuds, array $u, int $restant, array $lignee): void
    {
        $limite = ParametreService::entier('generations_lignee_feminine', -1);
        $ids = array_map(static fn(array $n): int => (int) $n['personne']['id'], $noeuds);
        $in = Database::placeholders($ids);
        $liens = Database::all("SELECT * FROM liens_parente WHERE statut <> 'rejete' AND parent_id IN ($in) ORDER BY enfant_id", $ids);
        $enfantsIds = array_values(array_unique(array_map('intval', array_column($liens, 'enfant_id'))));
        $enfants = PersonneRepository::parIds($enfantsIds);
        $conjoints = self::conjoints($enfantsIds);
        $unions = self::unions($ids);
        $fichesConjoints = PersonneRepository::parIds(array_merge(
            array_column($conjoints, 'parent_id'),
            array_column($unions, 'epouse_id'),
            array_column($unions, 'epoux_id')
        ));

        $suivants = [];
        foreach ($noeuds as &$n) {
            $parent = (int) $n['personne']['id'];
            $estFemme = $n['personne']['sexe'] === 'F';
            $parFille = $estFemme || ($lignee[$parent] ?? 0) > 0 ? ($lignee[$parent] ?? 0) + 1 : 0;
            $coupe = $limite >= 0 && $parFille > $limite;
            $foyers = [];
            $rangs = [];
            foreach ($unions as $un) {
                $moi = (int) ($estFemme ? $un['epouse_id'] : $un['epoux_id']);
                if ($moi === $parent) {
                    $conjoint = (int) ($estFemme ? $un['epoux_id'] : $un['epouse_id']);
                    $rangs[$conjoint] = (int) $un['rang_epouse'];
                    self::foyer($foyers, $conjoint, $un, $fichesConjoints, $u);
                }
            }
            foreach ($liens as $l) {
                $e = (int) $l['enfant_id'];
                if ((int) $l['parent_id'] !== $parent || $coupe || !isset($enfants[$e]) || !self::visible($l, $enfants[$e], $u)) {
                    continue;
                }
                $s = PersonneSerializer::selonRole($enfants[$e], $u) ?? PersonneSerializer::pourReferent($enfants[$e]);
                $cle = 0;
                foreach ($conjoints as $c) {
                    if ((int) $c['enfant_id'] === $e && (int) $c['parent_id'] !== $parent) {
                        $f = $fichesConjoints[(int) $c['parent_id']] ?? null;
                        if ($f !== null && self::visible($c, $f, $u)) {
                            $cle = (int) $c['parent_id'];
                            self::foyer($foyers, $cle, null, $fichesConjoints, $u);
                        }
                    }
                }
                if ($cle === 0 && !isset($foyers[0])) {
                    $foyers[0] = ['conjoint' => null, 'rang' => null, 'statut_union' => null, 'enfants' => []];
                }
                $lignee[$e] = $parFille;
                $foyers[$cle]['enfants'][] = ['personne' => $s, 'statut_lien' => PermissionService::estReferent($u) ? $l['statut'] : null];
            }
            // Le rang ordonne les foyers même si l'union n'est pas encore validée.
            foreach ($foyers as $cle => &$f) {
                $f['rang'] ??= $rangs[$cle] ?? null;
            }
            unset($f);
            uasort($foyers, static fn(array $a, array $b): int => ($a['rang'] ?? 99) <=> ($b['rang'] ?? 99));
            $n['foyers'] = array_values($foyers);
            foreach ($n['foyers'] as &$f) {
                foreach ($f['enfants'] as &$e) {
                    $suivants[] = &$e;
                }
                unset($e);
            }
            unset($f);
        }
        unset($n);

        if ($suivants === []) {
            return;
        }
        if ($restant > 1) {
            self::developper($suivants, $u, $restant - 1, $lignee);
            return;
        }
        $feuilles = array_map(static fn(array $n): int => (int) $n['personne']['id'], $suivants);
        $avecSuite = array_flip(Database::ids(
            'SELECT DISTINCT parent_id FROM liens_parente WHERE statut <> \'rejete\' AND parent_id IN (' . Database::placeholders($feuilles) . ')',
            $feuilles
        ));
        foreach ($suivants as &$n) {
            $n['a_suite'] = isset($avecSuite[(int) $n['personne']['id']]);
        }
    }

    private static function foyer(array &$foyers, int $conjointId, ?array $union, array $fiches, array $u): void
    {
        if (isset($foyers[$conjointId])) {
            if ($union !== null && $foyers[$conjointId]['rang'] === null) {
                $foyers[$conjointId]['rang'] = (int) $union['rang_epouse'];
                $foyers[$conjointId]['statut_union'] = PermissionService::estReferent($u) ? $union['statut'] : null;
            }
            return;
        }
        $f = $fiches[$conjointId] ?? null;
        if ($f === null || ($union !== null && !self::visible(['statut' => $union['statut']], $f, $u))) {
            return;
        }
        $foyers[$conjointId] = [
            'conjoint' => PersonneSerializer::selonRole($f, $u) ?? PersonneSerializer::pourReferent($f),
            'rang' => $union === null ? null : (int) $union['rang_epouse'],
            'statut_union' => $union !== null && PermissionService::estReferent($u) ? $union['statut'] : null,
            'enfants' => [],
        ];
    }

    private static function conjoints(array $enfantsIds): array
    {
        if ($enfantsIds === []) {
            return [];
        }
        return Database::all(
            "SELECT * FROM liens_parente WHERE statut <> 'rejete' AND enfant_id IN (" . Database::placeholders($enfantsIds) . ')',
            $enfantsIds
        );
    }

    private static function unions(array $ids): array
    {
        $in = Database::placeholders($ids);
        return Database::all(
            "SELECT * FROM unions WHERE statut <> 'rejete' AND (epoux_id IN ($in) OR epouse_id IN ($in)) ORDER BY rang_epouse",
            [...$ids, ...$ids]
        );
    }
}
