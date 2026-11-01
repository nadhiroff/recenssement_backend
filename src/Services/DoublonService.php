<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Repositories\LienRepository;
use App\Repositories\PersonneRepository;
use App\Serializers\PersonneSerializer;

final class DoublonService
{
    private static function charger(int $id): array
    {
        $d = Database::one('SELECT * FROM doublons_suspects WHERE id = ?', [$id]);
        if ($d === null) {
            throw new ApiException(404, 'introuvable', 'Paire introuvable.');
        }
        return $d;
    }

    /** Le référent traite une paire dont au moins une fiche relève de sa branche. */
    private static function exigerDroit(array $u, array $d): void
    {
        PermissionService::exigerReferent($u);
        $f = PersonneRepository::parIds([$d['personne_a_id'], $d['personne_b_id']]);
        foreach ($f as $p) {
            if (PermissionService::couvreBranche($u, $p['branche_id'] === null ? null : (int) $p['branche_id'])) {
                FusionService::exigerSansConflit($u, array_keys($f));
                return;
            }
        }
        throw new ApiException(403, 'interdit', 'Cette paire ne relève pas de votre branche.');
    }

    public static function liste(array $u, string $statut, int $limite, int $offset): array
    {
        PermissionService::exigerReferent($u);
        $sql = 'SELECT d.* FROM doublons_suspects d WHERE d.statut = ?';
        $params = [$statut];
        if (!PermissionService::estAdmin($u)) {
            $branches = PermissionService::branchesReferent($u);
            if ($branches === []) {
                return [];
            }
            $in = implode(',', $branches);
            $sql .= " AND EXISTS (SELECT 1 FROM personnes p WHERE p.id IN (d.personne_a_id, d.personne_b_id) AND p.branche_id IN ($in))";
        }
        $lignes = Database::all($sql . " ORDER BY d.score DESC, d.id LIMIT $limite OFFSET $offset", $params);
        $ids = array_merge(array_column($lignes, 'personne_a_id'), array_column($lignes, 'personne_b_id'));
        $fiches = PersonneRepository::parIds($ids);
        $parents = LienRepository::parentsDe($ids);
        $noms = PersonneRepository::parIds(array_merge(...array_map('array_values', array_values($parents ?: [[]]))));
        $vue = static function (int $id) use ($fiches, $parents, $noms, $u): array {
            $s = PersonneSerializer::selonRole($fiches[$id], $u) ?? ['id' => $id, 'nom_complet' => $fiches[$id]['nom_complet']];
            $p = $parents[$id] ?? [];
            return $s + [
                'pere' => isset($p['pere_id']) ? ($noms[$p['pere_id']]['nom_complet'] ?? null) : null,
                'mere' => isset($p['mere_id']) ? ($noms[$p['mere_id']]['nom_complet'] ?? null) : null,
            ];
        };
        return array_map(static fn(array $d): array => [
            'id' => (int) $d['id'],
            'score' => (int) $d['score'],
            'detail' => json_decode((string) $d['detail_score'], true),
            'a' => $vue((int) $d['personne_a_id']),
            'b' => $vue((int) $d['personne_b_id']),
        ], $lignes);
    }

    public static function fusionner(array $u, int $id, int $conservee): int
    {
        $d = self::charger($id);
        self::exigerDroit($u, $d);
        if ($d['statut'] !== 'a_traiter') {
            throw new ApiException(409, 'deja_traite', 'Cette paire a déjà été traitée.');
        }
        $a = (int) $d['personne_a_id'];
        $b = (int) $d['personne_b_id'];
        if ($conservee !== $a && $conservee !== $b) {
            throw new ApiException(422, 'validation', 'La fiche conservée doit faire partie de la paire.');
        }
        return Database::tx(static function () use ($u, $id, $conservee, $a, $b): int {
            $fusion = FusionService::fusionner($u, $conservee, $conservee === $a ? $b : $a, $id);
            Database::update('doublons_suspects', ['statut' => 'fusionne', 'traite_par' => $u['id'], 'traite_le' => gmdate('Y-m-d H:i:s')], 'id = ?', [$id]);
            return $fusion;
        });
    }

    public static function distinct(array $u, int $id): void
    {
        $d = self::charger($id);
        self::exigerDroit($u, $d);
        if ($d['statut'] !== 'a_traiter') {
            throw new ApiException(409, 'deja_traite', 'Cette paire a déjà été traitée.');
        }
        Database::tx(static function () use ($u, $d): void {
            $maintenant = gmdate('Y-m-d H:i:s');
            Database::insert('paires_distinctes', [
                'personne_a_id' => $d['personne_a_id'],
                'personne_b_id' => $d['personne_b_id'],
                'declare_par' => $u['id'],
                'declare_le' => $maintenant,
            ]);
            Database::update('doublons_suspects', ['statut' => 'distinct', 'traite_par' => $u['id'], 'traite_le' => $maintenant], 'id = ?', [$d['id']]);
            JournalService::ecrire((int) $u['id'], 'doublon.distinct', 'personne', (int) $d['personne_a_id'], null, ['autre' => (int) $d['personne_b_id']]);
        });
    }
}
