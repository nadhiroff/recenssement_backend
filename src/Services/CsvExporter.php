<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\PersonneRepository;

/** CSV pour Excel français : UTF-8 avec BOM, séparateur point-virgule. */
final class CsvExporter
{
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

    /** Empêche Excel d'interpréter un nom comme une formule. Le « + » des numéros reste valide. */
    private static function neutraliser(mixed $v): string
    {
        $s = (string) $v;
        return preg_match('/^(?:[=@\-\t\r]|\+(?!\d{8,15}$))/', $s) === 1 ? "'" . $s : $s;
    }

    public static function fiches(): string
    {
        $ids = Database::ids('SELECT p.id FROM personnes p WHERE p.fusionnee_dans_id IS NULL ORDER BY p.id');
        $branches = array_column(Database::all('SELECT id, nom FROM branches'), 'nom', 'id');
        $lignes = [];
        foreach (array_chunk($ids, 500) as $bloc) {
            foreach (PersonneRepository::parIds($bloc) as $p) {
                $autres = array_map(
                    static fn(array $n): string => $n['type'] . ':' . $n['valeur'],
                    array_filter($p['noms'], static fn(array $n): bool => !$n['principal'])
                );
                $lignes[] = [
                    $p['id'], $p['nom_complet'], implode(' | ', $autres), $p['sexe'], $p['annee_naissance'], $p['precision_naissance'],
                    $p['est_decede'] ? 'oui' : 'non', $p['annee_deces'], $branches[$p['branche_id'] ?? 0] ?? '', $p['telephone_whatsapp'], $p['email'],
                    $p['lieu_residence'], $p['est_privee'] ? 'oui' : 'non', $p['statut_fiche'],
                ];
            }
        }
        return self::tableau(['id', 'nom', 'autres_noms', 'sexe', 'annee_naissance', 'precision', 'decede', 'annee_deces', 'branche', 'telephone', 'email', 'residence', 'privee', 'statut'], $lignes);
    }

    public static function journal(array $lignes): string
    {
        return self::tableau(
            ['id', 'date', 'utilisateur', 'action', 'entite', 'entite_id', 'branche', 'details'],
            array_map(static fn(array $j): array => [$j['id'], $j['cree_le'], $j['utilisateur_id'], $j['action'], $j['entite_type'], $j['entite_id'], $j['branche_id'], $j['details']], $lignes)
        );
    }
}
