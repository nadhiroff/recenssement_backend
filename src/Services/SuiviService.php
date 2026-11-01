<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class SuiviService
{
    /** @param int[]|null $branches null = toutes */
    public static function stats(?array $branches): array
    {
        $filtre = '';
        if ($branches !== null) {
            $filtre = $branches === [] ? ' AND 1 = 0' : ' AND b.id IN (' . implode(',', array_map('intval', $branches)) . ')';
        }
        $res = [];
        foreach (Database::all("SELECT b.id, b.nom FROM branches b WHERE b.actif = 1$filtre ORDER BY b.ordre, b.nom") as $b) {
            $res[(int) $b['id']] = ['branche_id' => (int) $b['id'], 'nom' => $b['nom'], 'personnes' => 0, 'doublons' => 0,
                'liens' => ['declare' => 0, 'confirme' => 0, 'valide' => 0, 'en_examen' => 0, 'prive' => 0, 'rejete' => 0]];
        }
        foreach (Database::all("SELECT p.branche_id, COUNT(*) n FROM personnes p WHERE p.fusionnee_dans_id IS NULL AND p.statut_fiche = 'active' AND p.branche_id IS NOT NULL GROUP BY p.branche_id") as $l) {
            if (isset($res[(int) $l['branche_id']])) {
                $res[(int) $l['branche_id']]['personnes'] = (int) $l['n'];
            }
        }
        foreach (Database::all('SELECT e.branche_id, l.statut, COUNT(*) n FROM liens_parente l JOIN personnes e ON e.id = l.enfant_id WHERE e.branche_id IS NOT NULL GROUP BY e.branche_id, l.statut') as $l) {
            if (isset($res[(int) $l['branche_id']])) {
                $res[(int) $l['branche_id']]['liens'][$l['statut']] = (int) $l['n'];
            }
        }
        foreach (Database::all("SELECT p.branche_id, COUNT(*) n FROM doublons_suspects d JOIN personnes p ON p.id = d.personne_a_id WHERE d.statut = 'a_traiter' AND p.branche_id IS NOT NULL GROUP BY p.branche_id") as $l) {
            if (isset($res[(int) $l['branche_id']])) {
                $res[(int) $l['branche_id']]['doublons'] = (int) $l['n'];
            }
        }
        return array_values($res);
    }

    /** @param int[]|null $branches null = tout le journal */
    public static function journal(?array $branches, array $filtres, int $limite, int $offset): array
    {
        $conds = ['1 = 1'];
        $params = [];
        if ($branches !== null) {
            $conds[] = $branches === [] ? '1 = 0' : 'j.branche_id IN (' . implode(',', array_map('intval', $branches)) . ')';
        }
        foreach (['action' => 'j.action', 'entite_type' => 'j.entite_type', 'utilisateur_id' => 'j.utilisateur_id'] as $cle => $col) {
            if (($filtres[$cle] ?? '') !== '') {
                $conds[] = "$col = ?";
                $params[] = $filtres[$cle];
            }
        }
        return Database::all(
            'SELECT j.id, j.utilisateur_id, j.action, j.entite_type, j.entite_id, j.branche_id, j.details, j.cree_le FROM journal_actions j
             WHERE ' . implode(' AND ', $conds) . " ORDER BY j.id DESC LIMIT $limite OFFSET $offset",
            $params
        );
    }
}
