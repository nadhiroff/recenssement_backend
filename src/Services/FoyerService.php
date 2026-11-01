<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Validator;
use App\Repositories\PersonneRepository;
use App\Repositories\UnionRepository;

/** Le rang d'une épouse suit d'abord l'ordre de saisie : référent ou administrateur le corrige avec les aînés. */
final class FoyerService
{
    /** Le foyer concerne l'époux, ses épouses et les enfants de l'époux. */
    private static function concerne(array $u, int $epouxId, array $unions): bool
    {
        $moi = $u['personne_id'] === null ? null : (int) $u['personne_id'];
        if ($moi === null) {
            return false;
        }
        if ($moi === $epouxId || in_array($moi, array_map('intval', array_column($unions, 'epouse_id')), true)) {
            return true;
        }
        return Database::value("SELECT id FROM liens_parente WHERE parent_id = ? AND enfant_id = ? AND statut <> 'rejete'", [$epouxId, $moi]) !== null;
    }

    /** @param int[] $ordre unions dans l'ordre voulu ; celles non citées (masquées au lecteur) gardent leur place à la suite */
    public static function ordonner(array $u, int $epouxId, array $ordre): void
    {
        $epoux = PersonneRepository::trouver($epouxId);
        if ($epoux === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        $branche = $epoux['branche_id'] === null ? null : (int) $epoux['branche_id'];
        PermissionService::exigerBranche($u, $branche);
        $unions = UnionRepository::epouses($epouxId);
        if (self::concerne($u, $epouxId, $unions)) {
            throw new ApiException(403, 'conflit_interet', 'Ce foyer vous concerne : il doit être traité par un autre référent ou l\'administrateur.');
        }
        $connues = array_map('intval', array_column($unions, 'id'));
        $ordre = array_map('intval', $ordre);
        if ($ordre === [] || count($ordre) !== count(array_unique($ordre)) || array_diff($ordre, $connues) !== []) {
            Validator::echec('ordre', 'Ordre des épouses invalide.');
        }
        $final = [...$ordre, ...array_values(array_diff($connues, $ordre))];
        Database::tx(static function () use ($final, $u, $epouxId, $branche, $connues): void {
            foreach ($final as $i => $id) {
                Database::run('UPDATE unions SET rang_epouse = ? WHERE id = ?', [$i + 1, $id]);
            }
            JournalService::ecrire((int) $u['id'], 'union.rang', 'personne', $epouxId, $branche, ['avant' => $connues, 'apres' => $final]);
        });
    }

    /** Pères ayant au moins deux épouses, avec leurs épouses dans l'ordre. */
    public static function peresPolygames(int $limite, int $offset): array
    {
        $ids = Database::ids(
            "SELECT epoux_id FROM unions WHERE statut <> 'rejete' GROUP BY epoux_id HAVING COUNT(*) >= 2 ORDER BY epoux_id LIMIT $limite OFFSET $offset"
        );
        $res = [];
        foreach ($ids as $id) {
            $unions = UnionRepository::epouses($id);
            $res[] = ['epoux' => PersonneRepository::trouver($id), 'unions' => $unions, 'fiches' => PersonneRepository::parIds(array_column($unions, 'epouse_id'))];
        }
        return $res;
    }

    public static function totalPeres(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM (SELECT epoux_id FROM unions WHERE statut <> 'rejete' GROUP BY epoux_id HAVING COUNT(*) >= 2) x");
    }
}
