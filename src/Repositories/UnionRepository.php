<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UnionRepository
{
    public static function creerSiAbsente(int $epouxId, int $epouseId, ?int $annee = null, string $precision = 'inconnue'): int
    {
        $existante = Database::value("SELECT id FROM unions WHERE epoux_id = ? AND epouse_id = ? AND statut <> 'rejete'", [$epouxId, $epouseId]);
        if ($existante !== null) {
            return (int) $existante;
        }
        $rang = 1 + (int) Database::value("SELECT COALESCE(MAX(rang_epouse), 0) FROM unions WHERE epoux_id = ? AND statut <> 'rejete'", [$epouxId]);
        return Database::insert('unions', [
            'epoux_id' => $epouxId,
            'epouse_id' => $epouseId,
            'rang_epouse' => $rang,
            'annee_union' => $annee,
            'precision_union' => $precision,
        ]);
    }

    public static function epouses(int $epouxId): array
    {
        return Database::all(
            "SELECT id, epouse_id, rang_epouse, annee_union, precision_union, statut FROM unions
             WHERE epoux_id = ? AND statut <> 'rejete' ORDER BY rang_epouse, id",
            [$epouxId]
        );
    }
}
