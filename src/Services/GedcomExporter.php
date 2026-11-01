<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\PersonneRepository;

/** GEDCOM 5.5.1 de l'arbre validé : seuls les liens validés et les fiches publiques en sortent. */
final class GedcomExporter
{
    public static function generer(): string
    {
        $liens = Database::all(
            "SELECT l.parent_id, l.enfant_id, l.role_parent FROM liens_parente l
             JOIN personnes p ON p.id = l.parent_id JOIN personnes e ON e.id = l.enfant_id
             WHERE l.statut = 'valide' AND p.est_privee = 0 AND e.est_privee = 0
               AND p.statut_fiche = 'active' AND e.statut_fiche = 'active'
               AND p.fusionnee_dans_id IS NULL AND e.fusionnee_dans_id IS NULL"
        );
        $unions = Database::all(
            "SELECT u.epoux_id, u.epouse_id FROM unions u JOIN personnes a ON a.id = u.epoux_id JOIN personnes b ON b.id = u.epouse_id
             WHERE u.statut = 'valide' AND a.est_privee = 0 AND b.est_privee = 0 AND a.statut_fiche = 'active' AND b.statut_fiche = 'active'
               AND a.fusionnee_dans_id IS NULL AND b.fusionnee_dans_id IS NULL"
        );

        // Une famille par couple père/mère : un père à deux épouses a deux FAM.
        $parents = [];
        foreach ($liens as $l) {
            $parents[(int) $l['enfant_id']][$l['role_parent']] = (int) $l['parent_id'];
        }
        $familles = [];
        foreach ($unions as $u) {
            $familles["{$u['epoux_id']}-{$u['epouse_id']}"] = ['pere' => (int) $u['epoux_id'], 'mere' => (int) $u['epouse_id'], 'enfants' => []];
        }
        foreach ($parents as $enfant => $p) {
            $cle = ($p['pere'] ?? 0) . '-' . ($p['mere'] ?? 0);
            $familles[$cle] ??= ['pere' => $p['pere'] ?? null, 'mere' => $p['mere'] ?? null, 'enfants' => []];
            $familles[$cle]['enfants'][] = $enfant;
        }
        $familles = array_values($familles);

        $ids = [];
        foreach ($familles as $f) {
            foreach ([$f['pere'], $f['mere'], ...$f['enfants']] as $id) {
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }
        $fondateur = Database::value('SELECT id FROM personnes WHERE est_fondateur = 1 AND fusionnee_dans_id IS NULL AND est_privee = 0 LIMIT 1');
        if ($fondateur !== null) {
            $ids[(int) $fondateur] = true;
        }
        $fiches = PersonneRepository::parIds(array_keys($ids));

        $fams = [];
        $famc = [];
        foreach ($familles as $i => $f) {
            $x = '@F' . ($i + 1) . '@';
            foreach ([$f['pere'], $f['mere']] as $p) {
                if ($p !== null) {
                    $fams[$p][] = $x;
                }
            }
            foreach ($f['enfants'] as $e) {
                $famc[$e][] = $x;
            }
        }

        $l = [
            '0 HEAD',
            '1 SOUR RECENSEMENT_ZOCLI',
            '2 VERS 1.0',
            '2 NAME ' . APP_NAME,
            '1 DATE ' . strtoupper(gmdate('j M Y')),
            '1 SUBM @SUB1@',
            '1 FILE recensement_zocli.ged',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
            '1 CHAR UTF-8',
            '1 LANG French',
            '0 @SUB1@ SUBM',
            '1 NAME Famille ZOCLI de Sahoue Doutou',
        ];
        ksort($fiches);
        foreach ($fiches as $p) {
            $l = array_merge($l, self::individu($p, $fams[(int) $p['id']] ?? [], $famc[(int) $p['id']] ?? []));
        }
        foreach ($familles as $i => $f) {
            $l[] = '0 @F' . ($i + 1) . '@ FAM';
            if ($f['pere'] !== null) {
                $l[] = '1 HUSB @I' . $f['pere'] . '@';
            }
            if ($f['mere'] !== null) {
                $l[] = '1 WIFE @I' . $f['mere'] . '@';
            }
            foreach ($f['enfants'] as $e) {
                $l[] = '1 CHIL @I' . $e . '@';
            }
        }
        $l[] = '0 TRLR';
        return implode("\r\n", array_map([self::class, 'couper'], $l)) . "\r\n";
    }

    private static function couper(string $ligne): string
    {
        return mb_strlen($ligne) <= 240 ? $ligne : mb_substr($ligne, 0, 240);
    }

    private static function nettoyer(string $s): string
    {
        return trim((string) preg_replace('/[\/@\r\n]+/', ' ', $s));
    }

    public static function dateGedcom(?int $annee, string $precision, ?int $mois = null, ?int $jour = null): ?string
    {
        if ($annee === null || $precision === 'inconnue') {
            return null;
        }
        if ($precision === 'exacte' && $mois !== null) {
            $m = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'][$mois - 1];
            return trim(($jour === null ? '' : "$jour ") . "$m $annee");
        }
        return match ($precision) {
            'exacte' => (string) $annee,
            'environ' => "ABT $annee",
            'decennie' => 'BET ' . ($annee - $annee % 10) . ' AND ' . ($annee - $annee % 10 + 9),
            default => null,
        };
    }

    private static function individu(array $p, array $fams, array $famc): array
    {
        $famille = '';
        $prenom = '';
        $autres = [];
        foreach ($p['noms'] as $n) {
            $v = self::nettoyer($n['valeur']);
            if ($n['type'] === 'nom_famille' && $famille === '') {
                $famille = $v;
            } elseif ($n['type'] === 'naissance' && $prenom === '') {
                $prenom = $v;
            } elseif ($n['type'] !== 'nom_famille') {
                $autres[] = [$v, $n['type']];
            }
        }
        $l = ['0 @I' . $p['id'] . '@ INDI', "1 NAME $prenom /$famille/"];
        if ($prenom !== '') {
            $l[] = "2 GIVN $prenom";
        }
        if ($famille !== '') {
            $l[] = "2 SURN $famille";
        }
        foreach ($autres as [$v, $type]) {
            $l[] = "1 NAME $v //";
            $l[] = '2 TYPE ' . ($type === 'naissance' ? 'aka' : $type);
        }
        if (in_array($p['sexe'], ['M', 'F'], true)) {
            $l[] = '1 SEX ' . $p['sexe'];
        }
        // L'arbre est imprimé : la date complète d'une personne vivante n'en sort pas, seulement son année.
        $dateComplete = (bool) $p['est_decede'];
        $n = self::dateGedcom(
            $p['annee_naissance'] === null ? null : (int) $p['annee_naissance'],
            $p['precision_naissance'],
            $dateComplete && $p['mois_naissance'] !== null ? (int) $p['mois_naissance'] : null,
            $dateComplete && $p['jour_naissance'] !== null ? (int) $p['jour_naissance'] : null
        );
        if ($n !== null) {
            $l[] = '1 BIRT';
            $l[] = "2 DATE $n";
        }
        if ($p['est_decede']) {
            $d = self::dateGedcom($p['annee_deces'] === null ? null : (int) $p['annee_deces'], $p['precision_deces']);
            if ($d !== null) {
                $l[] = '1 DEAT';
                $l[] = "2 DATE $d";
            } else {
                $l[] = '1 DEAT Y';
            }
        }
        foreach ($famc as $x) {
            $l[] = "1 FAMC $x";
        }
        foreach ($fams as $x) {
            $l[] = "1 FAMS $x";
        }
        return $l;
    }
}
