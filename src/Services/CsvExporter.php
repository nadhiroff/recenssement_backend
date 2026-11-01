<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\PersonneRepository;

/** Fiches et arbre en tableaux lisibles, pour Excel (.xlsx) et CSV (UTF-8 avec BOM, point-virgule). */
final class CsvExporter
{
    private const ENTETES_FICHES = ['N°', 'Nom', 'Autres noms', 'Sexe', 'Naissance', 'Décès', 'Branche', 'Téléphone', 'Email', 'Résidence', 'Fiche privée', 'État'];
    private const ENTETES_ARBRE = ['N°', 'Nom', 'Sexe', 'Naissance', 'Décès', 'Branche', 'Père', 'Mère', 'Conjoint(s)', 'Enfants'];
    private const TYPES_NOMS = ['vodoun' => 'Vodoun', 'bapteme' => 'Baptême', 'surnom' => 'Surnom', 'naissance' => 'Prénom', 'nom_famille' => 'Nom'];
    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public static function tableau(array $entetes, iterable $lignes): string
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $entetes, ';', '"', '');
        foreach ($lignes as $l) {
            fputcsv($f, array_map([self::class, 'neutraliser'], $l), ';', '"', '');
        }
        rewind($f);
        return (string) stream_get_contents($f);
    }

    /** Empêche Excel d'interpréter un nom comme une formule. */
    private static function neutraliser(mixed $v): string
    {
        $s = (string) $v;
        return preg_match('/^(?:[=@\-\t\r]|\+(?![\d ]{8,20}$))/', $s) === 1 ? "'" . $s : $s;
    }

    /** +229 01 97 12 34 56 : lisible, et les espaces empêchent Excel d'en faire un nombre. */
    public static function telephone(?string $t): string
    {
        if ($t === null || $t === '') {
            return '';
        }
        $pays = str_starts_with($t, '+229') ? 4 : max(1, strlen($t) - 9);
        return substr($t, 0, $pays) . ' ' . trim(chunk_split(substr($t, $pays), 2, ' '));
    }

    public static function date(?int $annee, string $precision, ?int $mois = null, ?int $jour = null): string
    {
        if ($annee === null || $precision === 'inconnue') {
            return '';
        }
        if ($jour !== null && $mois !== null) {
            return $jour . ' ' . self::MOIS[$mois - 1] . ' ' . $annee;
        }
        return match ($precision) {
            'environ' => "vers $annee",
            'decennie' => 'années ' . ($annee - $annee % 10),
            default => (string) $annee,
        };
    }

    private static function sexe(string $s): string
    {
        return ['M' => 'Homme', 'F' => 'Femme'][$s] ?? '';
    }

    private static function deces(array $p): string
    {
        if (!$p['est_decede']) {
            return '';
        }
        return self::date($p['annee_deces'] === null ? null : (int) $p['annee_deces'], $p['precision_deces']) ?: 'Décédé(e)';
    }

    private static function naissance(array $p): string
    {
        return self::date(
            $p['annee_naissance'] === null ? null : (int) $p['annee_naissance'],
            $p['precision_naissance'],
            $p['mois_naissance'] === null ? null : (int) $p['mois_naissance'],
            $p['jour_naissance'] === null ? null : (int) $p['jour_naissance']
        );
    }

    /** @return array{0:string[], 1:array[]} */
    public static function donneesFiches(): array
    {
        $ids = Database::ids('SELECT p.id FROM personnes p WHERE p.fusionnee_dans_id IS NULL ORDER BY p.id');
        $branches = array_column(Database::all('SELECT id, nom FROM branches'), 'nom', 'id');
        $lignes = [];
        foreach (array_chunk($ids, 500) as $bloc) {
            foreach (PersonneRepository::parIds($bloc) as $p) {
                $autres = array_map(
                    static fn(array $n): string => (self::TYPES_NOMS[$n['type']] ?? $n['type']) . ' : ' . $n['valeur'],
                    array_filter($p['noms'], static fn(array $n): bool => !$n['principal'])
                );
                $lignes[] = [
                    (int) $p['id'], $p['nom_complet'], implode(', ', $autres), self::sexe($p['sexe']), self::naissance($p), self::deces($p),
                    $branches[$p['branche_id'] ?? 0] ?? '', self::telephone($p['telephone_whatsapp']), $p['email'], $p['lieu_residence'],
                    $p['est_privee'] ? 'Oui' : 'Non', $p['statut_fiche'] === 'active' ? 'Active' : 'Retirée',
                ];
            }
        }
        return [self::ENTETES_FICHES, $lignes];
    }

    /**
     * L'arbre validé en clair : mêmes personnes et mêmes liens que le GEDCOM, sans téléphone,
     * et seulement l'année pour les personnes vivantes, car ce document peut circuler.
     * @return array{0:string[], 1:array[]}
     */
    public static function donneesArbre(): array
    {
        $publique = "p.est_privee = 0 AND p.statut_fiche = 'active' AND p.fusionnee_dans_id IS NULL";
        $liens = Database::all(
            "SELECT l.parent_id, l.enfant_id, l.role_parent FROM liens_parente l
             JOIN personnes p ON p.id = l.parent_id JOIN personnes e ON e.id = l.enfant_id
             WHERE l.statut = 'valide' AND $publique AND e.est_privee = 0 AND e.statut_fiche = 'active' AND e.fusionnee_dans_id IS NULL"
        );
        $unions = Database::all(
            "SELECT u.epoux_id, u.epouse_id FROM unions u JOIN personnes p ON p.id = u.epoux_id JOIN personnes b ON b.id = u.epouse_id
             WHERE u.statut = 'valide' AND $publique AND b.est_privee = 0 AND b.statut_fiche = 'active' AND b.fusionnee_dans_id IS NULL ORDER BY u.rang_epouse"
        );
        $ids = array_merge(array_column($liens, 'parent_id'), array_column($liens, 'enfant_id'), array_column($unions, 'epoux_id'), array_column($unions, 'epouse_id'));
        $fondateur = Database::value('SELECT p.id FROM personnes p WHERE p.est_fondateur = 1 AND ' . $publique . ' LIMIT 1');
        if ($fondateur !== null) {
            $ids[] = (int) $fondateur;
        }
        $fiches = PersonneRepository::parIds($ids);
        ksort($fiches);
        $nom = static fn(int $id): string => $fiches[$id]['nom_complet'] ?? '';
        $parents = [];
        $enfants = [];
        foreach ($liens as $l) {
            $parents[(int) $l['enfant_id']][$l['role_parent']] = $nom((int) $l['parent_id']);
            $enfants[(int) $l['parent_id']][] = $nom((int) $l['enfant_id']);
        }
        $conjoints = [];
        foreach ($unions as $u) {
            $conjoints[(int) $u['epoux_id']][] = $nom((int) $u['epouse_id']);
            $conjoints[(int) $u['epouse_id']][] = $nom((int) $u['epoux_id']);
        }
        $branches = array_column(Database::all('SELECT id, nom FROM branches'), 'nom', 'id');
        $lignes = [];
        foreach ($fiches as $id => $p) {
            $naissance = $p['est_decede'] ? self::naissance($p) : self::date($p['annee_naissance'] === null ? null : (int) $p['annee_naissance'], $p['precision_naissance']);
            $lignes[] = [(int) $id, $p['nom_complet'], self::sexe($p['sexe']), $naissance, self::deces($p), $branches[$p['branche_id'] ?? 0] ?? '',
                $parents[$id]['pere'] ?? '', $parents[$id]['mere'] ?? '', implode(', ', $conjoints[$id] ?? []), implode(', ', $enfants[$id] ?? [])];
        }
        return [self::ENTETES_ARBRE, $lignes];
    }

    public static function fiches(): string
    {
        return self::tableau(...self::donneesFiches());
    }

    /** @param array[] $lignes lignes du journal ; $libelle traduit le code d'action */
    public static function donneesJournal(array $lignes, callable $libelle): array
    {
        return [
            ['N°', 'Date', 'Compte', 'Action', 'Sur', 'Détails'],
            array_map(static fn(array $j): array => [(int) $j['id'], $j['cree_le'], $j['utilisateur_id'] === null ? 'Système' : 'Compte n° ' . $j['utilisateur_id'], $libelle($j['action']), trim($j['entite_type'] . ' ' . $j['entite_id']), $j['details']], $lignes),
        ];
    }
}
