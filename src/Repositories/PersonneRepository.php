<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\NormalisationService;

final class PersonneRepository
{
    /** Fiche vivante : ni absorbée par une fusion, ni retirée. */
    public const ACTIVE = "p.fusionnee_dans_id IS NULL AND p.statut_fiche = 'active'";
    public const VISIBLE_MEMBRE = self::ACTIVE . ' AND p.est_privee = 0';

    public static function trouver(int $id): ?array
    {
        return self::parIds([$id])[$id] ?? null;
    }

    /** @return array<int, array> fiches hydratées (noms, nom_complet), indexées par id */
    public static function parIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = Database::placeholders($ids);
        $res = [];
        foreach (Database::all("SELECT * FROM personnes WHERE id IN ($in)", $ids) as $p) {
            $p['noms'] = [];
            $res[(int) $p['id']] = $p;
        }
        $noms = Database::all(
            "SELECT id, personne_id, type, valeur, principal FROM noms_personne WHERE personne_id IN ($in) ORDER BY principal DESC, id",
            $ids
        );
        foreach ($noms as $n) {
            $n['principal'] = (bool) $n['principal'];
            $res[(int) $n['personne_id']]['noms'][] = $n;
        }
        foreach ($res as &$p) {
            $p['nom_complet'] = self::nomComplet($p['noms']);
        }
        return $res;
    }

    public static function nomComplet(array $noms): string
    {
        $famille = '';
        $prenom = '';
        foreach ($noms as $n) {
            if ($n['type'] === 'nom_famille' && $famille === '') {
                $famille = $n['valeur'];
            } elseif ($n['type'] === 'naissance' && $prenom === '') {
                $prenom = $n['valeur'];
            }
        }
        if ($prenom === '' && isset($noms[0])) {
            foreach ($noms as $n) {
                if ($n['type'] !== 'nom_famille') {
                    $prenom = $n['valeur'];
                    break;
                }
            }
        }
        return trim($prenom . ' ' . $famille);
    }

    /**
     * @param array $d champs de la fiche
     * @param array<int, array{type:string, valeur:string}> $noms
     */
    public static function creer(array $d, array $noms, ?int $creePar): int
    {
        $d['cree_par_utilisateur_id'] = $creePar;
        $id = Database::insert('personnes', $d);
        self::ajouterNoms($id, $noms);
        return $id;
    }

    public static function ajouterNoms(int $personneId, array $noms): void
    {
        $dejaPrincipal = [];
        foreach ($noms as $n) {
            $principal = in_array($n['type'], ['nom_famille', 'naissance'], true) && !isset($dejaPrincipal[$n['type']]);
            $dejaPrincipal[$n['type']] = true;
            $nomId = Database::insert('noms_personne', [
                'personne_id' => $personneId,
                'type' => $n['type'],
                'valeur' => $n['valeur'],
                'valeur_normalisee' => NormalisationService::normaliser($n['valeur']),
                'principal' => $principal ? 1 : 0,
            ]);
            NormalisationService::ecrireTokens($nomId, $n['valeur']);
        }
    }

    /** Remplace tous les noms de la fiche (correction). */
    public static function remplacerNoms(int $personneId, array $noms): void
    {
        Database::run('DELETE FROM noms_personne WHERE personne_id = ?', [$personneId]);
        self::ajouterNoms($personneId, $noms);
    }

    /**
     * Recherche par n'importe quel nom, préfixes de mots (« kos » trouve Kossi).
     * @param int[]|null $branchesPrivees branches dont les fiches privées sont visibles ; null = toutes
     * @return int[]
     */
    public static function rechercher(string $q, ?array $branchesPrivees, int $limite, int $offset): array
    {
        $tokens = array_slice(NormalisationService::tokens($q), 0, 5);
        if ($tokens === []) {
            return [];
        }
        $filtre = self::ACTIVE;
        if ($branchesPrivees !== null) {
            $in = implode(',', array_map('intval', $branchesPrivees));
            $filtre .= ' AND (p.est_privee = 0' . ($in !== '' ? " OR p.branche_id IN ($in)" : '') . ')';
        }
        $conds = [];
        $params = [];
        foreach ($tokens as $t) {
            $conds[] = 'EXISTS (SELECT 1 FROM noms_personne n JOIN noms_tokens t ON t.nom_id = n.id WHERE n.personne_id = p.id AND t.token LIKE ?)';
            $params[] = addcslashes($t, '%_\\') . '%';
        }
        $sql = "SELECT p.id FROM personnes p WHERE $filtre AND " . implode(' AND ', $conds)
            . ' ORDER BY p.id LIMIT ' . $limite . ' OFFSET ' . $offset;
        return Database::ids($sql, $params);
    }
}
