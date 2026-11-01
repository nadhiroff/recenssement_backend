<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class LienRepository
{
    public static function trouver(int $id): ?array
    {
        return Database::one(
            'SELECT l.*, e.branche_id FROM liens_parente l JOIN personnes e ON e.id = l.enfant_id WHERE l.id = ?',
            [$id]
        );
    }

    public static function creer(int $parentId, int $enfantId, string $role, ?int $declarantId, string $nature = 'biologique'): int
    {
        return Database::insert('liens_parente', [
            'parent_id' => $parentId,
            'enfant_id' => $enfantId,
            'role_parent' => $role,
            'nature_lien' => $nature,
            'statut' => 'declare',
            'declarant_utilisateur_id' => $declarantId,
        ]);
    }

    public static function actifPourRole(int $enfantId, string $role): ?array
    {
        return Database::one("SELECT * FROM liens_parente WHERE enfant_id = ? AND role_parent = ? AND statut <> 'rejete'", [$enfantId, $role]);
    }

    /** Vrai si $candidatId descend de $personneId : le déclarer parent créerait une boucle. */
    public static function estDescendant(int $candidatId, int $personneId): bool
    {
        $vus = [];
        $niveau = [$personneId];
        while ($niveau !== []) {
            $in = Database::placeholders($niveau);
            $enfants = Database::ids("SELECT enfant_id FROM liens_parente WHERE statut <> 'rejete' AND parent_id IN ($in)", $niveau);
            $niveau = [];
            foreach ($enfants as $e) {
                if ($e === $candidatId) {
                    return true;
                }
                if (!isset($vus[$e])) {
                    $vus[$e] = true;
                    $niveau[] = $e;
                }
            }
        }
        return false;
    }

    /** @return array<int, array{pere_id:?int, mere_id:?int}> parents non rejetés, par enfant */
    public static function parentsDe(array $ids): array
    {
        $res = [];
        if ($ids === []) {
            return $res;
        }
        $ids = array_values($ids);
        $in = Database::placeholders($ids);
        $liens = Database::all("SELECT enfant_id, parent_id, role_parent FROM liens_parente WHERE statut <> 'rejete' AND enfant_id IN ($in)", $ids);
        foreach ($liens as $l) {
            $res[(int) $l['enfant_id']][$l['role_parent'] === 'pere' ? 'pere_id' : 'mere_id'] = (int) $l['parent_id'];
        }
        return $res;
    }
}
