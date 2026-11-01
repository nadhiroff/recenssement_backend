<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\LienRepository;
use App\Repositories\PersonneRepository;

final class MatchingService
{
    public static function poids(): array
    {
        return [
            'prenom' => ParametreService::entier('score_prenom_commun', 30),
            'branche' => ParametreService::entier('score_meme_branche', 20),
            'pere' => ParametreService::entier('score_meme_pere', 25),
            'naissance' => ParametreService::entier('score_naissance_5ans', 15),
            'mere' => ParametreService::entier('score_meme_mere', 10),
            'ecart' => ParametreService::entier('ecart_annees_max', 5),
        ];
    }

    /**
     * Score pur, sans accès base.
     * $a et $b : tokens[] (prénoms et autres noms, hors nom de famille), branche_id, annee, pere_id, mere_id.
     * $a peut porter pere_tokens/mere_tokens : noms du parent saisis quand sa fiche n'est pas connue, comparés à ceux de $b.
     * @return array{score:int, detail:array<string,int>}
     */
    public static function scorer(array $a, array $b, array $p): array
    {
        $detail = [];
        if (array_intersect($a['tokens'] ?? [], $b['tokens'] ?? []) !== []) {
            $detail['prenom'] = $p['prenom'];
        }
        if (!empty($a['branche_id']) && (int) $a['branche_id'] === (int) ($b['branche_id'] ?? 0)) {
            $detail['branche'] = $p['branche'];
        }
        foreach (['pere', 'mere'] as $role) {
            $idA = $a[$role . '_id'] ?? null;
            $idB = $b[$role . '_id'] ?? null;
            $parNom = array_intersect($a[$role . '_tokens'] ?? [], $b[$role . '_tokens'] ?? []) !== [];
            if (($idA !== null && $idA === $idB) || ($idA === null && $parNom)) {
                $detail[$role] = $p[$role];
            }
        }
        if (isset($a['annee'], $b['annee']) && abs((int) $a['annee'] - (int) $b['annee']) <= $p['ecart']) {
            $detail['naissance'] = $p['naissance'];
        }
        return ['score' => array_sum($detail), 'detail' => $detail];
    }

    /** @return array<int, string[]> par personne, mots de tous ses noms sauf le nom de famille */
    public static function tokensDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values($ids);
        $in = Database::placeholders($ids);
        $res = [];
        $lignes = Database::all(
            "SELECT n.personne_id, t.token FROM noms_personne n JOIN noms_tokens t ON t.nom_id = n.id
             WHERE n.type <> 'nom_famille' AND n.personne_id IN ($in)",
            $ids
        );
        foreach ($lignes as $l) {
            $res[(int) $l['personne_id']][] = $l['token'];
        }
        return $res;
    }

    /** @return array<int, array> profils de comparaison, par personne */
    public static function profils(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values($ids);
        $in = Database::placeholders($ids);
        $lignes = Database::all("SELECT id, branche_id, annee_naissance, precision_naissance FROM personnes WHERE id IN ($in)", $ids);
        $tokens = self::tokensDe($ids);
        $parents = LienRepository::parentsDe($ids);
        $res = [];
        foreach ($lignes as $l) {
            $id = (int) $l['id'];
            $res[$id] = [
                'id' => $id,
                'tokens' => array_values(array_unique($tokens[$id] ?? [])),
                'branche_id' => $l['branche_id'] === null ? null : (int) $l['branche_id'],
                'annee' => $l['precision_naissance'] === 'inconnue' ? null : ($l['annee_naissance'] === null ? null : (int) $l['annee_naissance']),
                'pere_id' => $parents[$id]['pere_id'] ?? null,
                'mere_id' => $parents[$id]['mere_id'] ?? null,
            ];
        }
        return $res;
    }

    /**
     * Fiches ressemblant au critère, au-dessus du seuil de proposition, meilleures d'abord.
     * @return array<int, array{personne_id:int, score:int, detail:array}>
     */
    public static function candidats(array $crit): array
    {
        $tokens = $crit['tokens'];
        $ids = [];
        if ($tokens !== []) {
            $in = Database::placeholders($tokens);
            $ids = Database::ids(
                'SELECT DISTINCT p.id FROM personnes p JOIN noms_personne n ON n.personne_id = p.id JOIN noms_tokens t ON t.nom_id = n.id
                 WHERE ' . PersonneRepository::ACTIVE . " AND n.type <> 'nom_famille' AND t.token IN ($in) LIMIT 300",
                $tokens
            );
        }
        if (!empty($crit['branche_id'])) {
            $ids = array_merge($ids, Database::ids(
                'SELECT p.id FROM personnes p WHERE ' . PersonneRepository::ACTIVE . ' AND p.branche_id = ? LIMIT 300',
                [$crit['branche_id']]
            ));
        }
        $ids = array_values(array_unique($ids));
        if (!empty($crit['exclure_id'])) {
            $ids = array_values(array_diff($ids, [(int) $crit['exclure_id']]));
        }
        if ($ids === []) {
            return [];
        }
        $profils = self::profils($ids);
        self::ajouterTokensParents($profils, $crit);

        $poids = self::poids();
        $seuil = ParametreService::entier('seuil_proposition', 50);
        $res = [];
        foreach ($profils as $id => $profil) {
            $s = self::scorer($crit, $profil, $poids);
            if ($s['score'] >= $seuil) {
                $res[] = ['personne_id' => $id, 'score' => $s['score'], 'detail' => $s['detail']];
            }
        }
        usort($res, static fn(array $x, array $y): int => $y['score'] <=> $x['score'] ?: $x['personne_id'] <=> $y['personne_id']);
        return array_slice($res, 0, 10);
    }

    /** Charge les noms du père/mère des candidats, seulement si le critère compare par nom. */
    private static function ajouterTokensParents(array &$profils, array $crit): void
    {
        foreach (['pere', 'mere'] as $role) {
            if (empty($crit[$role . '_tokens'])) {
                continue;
            }
            $parentIds = array_values(array_filter(array_column($profils, $role . '_id')));
            $tokens = self::tokensDe(array_unique($parentIds));
            foreach ($profils as &$pr) {
                $pr[$role . '_tokens'] = $tokens[$pr[$role . '_id'] ?? 0] ?? [];
            }
            unset($pr);
        }
    }

    /** Tâche hebdomadaire : paires au-dessus du seuil, par blocs. @return array{analysees:int, nouvelles:int} */
    public static function detecterDoublons(): array
    {
        $ids = Database::ids('SELECT p.id FROM personnes p WHERE ' . PersonneRepository::ACTIVE);
        $profils = self::profils($ids);
        $poids = self::poids();
        $seuil = ParametreService::entier('seuil_doublon', 70);
        $prenomRequis = ParametreService::entier('doublon_prenom_requis', 0) === 1;

        $distinctes = [];
        foreach (Database::all('SELECT personne_a_id, personne_b_id FROM paires_distinctes') as $d) {
            $distinctes[$d['personne_a_id'] . '-' . $d['personne_b_id']] = true;
        }
        $connues = [];
        foreach (Database::all('SELECT personne_a_id, personne_b_id, statut FROM doublons_suspects') as $d) {
            $connues[$d['personne_a_id'] . '-' . $d['personne_b_id']] = $d['statut'];
        }

        $blocs = [];
        foreach ($profils as $id => $pr) {
            if ($pr['branche_id'] !== null) {
                $blocs['b' . $pr['branche_id']][] = $id;
            }
            foreach ($pr['tokens'] as $t) {
                $blocs['t' . $t][] = $id;
            }
        }

        $vues = [];
        $analysees = 0;
        $nouvelles = 0;
        foreach ($blocs as $bloc) {
            sort($bloc);
            $n = count($bloc);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $cle = $bloc[$i] . '-' . $bloc[$j];
                    if (isset($vues[$cle])) {
                        continue;
                    }
                    $vues[$cle] = true;
                    $analysees++;
                    if (isset($distinctes[$cle])) {
                        continue;
                    }
                    $s = self::scorer($profils[$bloc[$i]], $profils[$bloc[$j]], $poids);
                    if ($s['score'] < $seuil || ($prenomRequis && !isset($s['detail']['prenom']))) {
                        continue;
                    }
                    if (!isset($connues[$cle])) {
                        Database::insert('doublons_suspects', [
                            'personne_a_id' => $bloc[$i],
                            'personne_b_id' => $bloc[$j],
                            'score' => $s['score'],
                            'detail_score' => json_encode($s['detail']),
                        ]);
                        $nouvelles++;
                    } elseif ($connues[$cle] === 'a_traiter') {
                        Database::run(
                            'UPDATE doublons_suspects SET score = ?, detail_score = ? WHERE personne_a_id = ? AND personne_b_id = ?',
                            [$s['score'], json_encode($s['detail']), $bloc[$i], $bloc[$j]]
                        );
                    }
                }
            }
        }
        return ['analysees' => $analysees, 'nouvelles' => $nouvelles];
    }
}
