<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;

final class BackupService
{
    private const GARDER_QUOTIDIENNES = 7;
    private const GARDER_HEBDOMADAIRES = 4;

    public static function dossier(): string
    {
        return BASE_PATH . '/storage/backups';
    }

    public static function sauvegarder(?bool $forcerPhp = null): array
    {
        $nom = 'zocli_quotidienne_' . gmdate('Ymd_His') . '.sql.gz';
        $chemin = self::dossier() . '/' . $nom;
        $outil = $forcerPhp ? 'php' : (self::viaMysqldump($chemin) ? 'mysqldump' : 'php');
        if ($outil === 'php') {
            self::viaPhp($chemin);
        }
        if (gmdate('N') === '7') {
            copy($chemin, self::dossier() . '/' . str_replace('quotidienne', 'hebdomadaire', $nom));
        }
        self::rotation('quotidienne', self::GARDER_QUOTIDIENNES);
        self::rotation('hebdomadaire', self::GARDER_HEBDOMADAIRES);
        return ['fichier' => $nom, 'taille' => (int) filesize($chemin), 'outil' => $outil];
    }

    private static function viaMysqldump(string $chemin): bool
    {
        if (!function_exists('exec') || trim((string) @shell_exec('command -v mysqldump')) === '') {
            return false;
        }
        $defauts = tempnam(sys_get_temp_dir(), 'zb');
        chmod($defauts, 0600);
        file_put_contents($defauts, sprintf("[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n", DB_HOST, DB_PORT, DB_USER, addcslashes(DB_PASS, '"\\')));
        $cmd = sprintf(
            'set -o pipefail; mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF --hex-blob --default-character-set=utf8mb4 %s 2>/dev/null | gzip -9 > %s',
            escapeshellarg($defauts), escapeshellarg(DB_NAME), escapeshellarg($chemin)
        );
        exec('bash -c ' . escapeshellarg($cmd), $sortie, $code);
        unlink($defauts);
        if ($code !== 0) {
            @unlink($chemin);
            return false;
        }
        return true;
    }

    /** Repli pour les hébergeurs sans mysqldump : même format, relu par restaurer(). */
    private static function viaPhp(string $chemin): void
    {
        $pdo = Database::pdo();
        $gz = gzopen($chemin, 'wb9');
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $creation = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$creation;\n");
            $colonnes = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) {
                if (!str_contains(strtoupper((string) $c['Extra']), 'GENERATED')) {
                    $colonnes[] = $c['Field'];
                }
            }
            $liste = '`' . implode('`,`', $colonnes) . '`';
            foreach ($pdo->query("SELECT $liste FROM `$t`", \PDO::FETCH_NUM) as $ligne) {
                $vals = array_map(static fn(mixed $v): string => $v === null ? 'NULL' : $pdo->quote((string) $v), $ligne);
                gzwrite($gz, "INSERT INTO `$t` ($liste) VALUES (" . implode(',', $vals) . ");\n");
            }
        }
        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll() as $tr) {
            $sql = $pdo->query("SHOW CREATE TRIGGER `{$tr['Trigger']}`")->fetch(\PDO::FETCH_ASSOC)['SQL Original Statement'];
            gzwrite($gz, "DELIMITER ;;\n" . self::sansDefiner($sql) . ";;\nDELIMITER ;\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }

    private static function sansDefiner(string $sql): string
    {
        $sql = (string) preg_replace('#/\*!50017 DEFINER=[^*]*\*/\s*#', '', $sql);
        return (string) preg_replace('/DEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/', '', $sql);
    }

    private static function rotation(string $type, int $garder): void
    {
        $fichiers = glob(self::dossier() . "/zocli_{$type}_*.sql.gz") ?: [];
        rsort($fichiers);
        foreach (array_slice($fichiers, $garder) as $f) {
            unlink($f);
        }
    }

    public static function liste(): array
    {
        $res = [];
        foreach (glob(self::dossier() . '/zocli_*.sql.gz') ?: [] as $f) {
            $res[] = ['fichier' => basename($f), 'taille' => (int) filesize($f), 'date' => gmdate('Y-m-d H:i:s', (int) filemtime($f))];
        }
        usort($res, static fn(array $a, array $b): int => strcmp($b['fichier'], $a['fichier']));
        return $res;
    }

    public static function chemin(string $fichier): string
    {
        if (!preg_match('/^zocli_(quotidienne|hebdomadaire)_\d{8}_\d{6}\.sql\.gz$/', $fichier) || !is_file(self::dossier() . '/' . $fichier)) {
            throw new ApiException(404, 'introuvable', 'Sauvegarde introuvable.');
        }
        return self::dossier() . '/' . $fichier;
    }

    /** Rejoue un fichier de sauvegarde, instruction par instruction, en PHP pur. */
    public static function restaurer(string $fichier): int
    {
        $chemin = self::chemin($fichier);
        $pdo = Database::pdo();
        $gz = gzopen($chemin, 'rb');
        if ($gz === false) {
            throw new ApiException(500, 'lecture_impossible', 'Lecture de la sauvegarde impossible.');
        }
        $delimiteur = ';';
        $tampon = '';
        $n = 0;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        while (($ligne = gzgets($gz)) !== false) {
            $net = rtrim($ligne, "\r\n");
            if ($tampon === '' && preg_match('/^DELIMITER\s+(\S+)/', $net, $m)) {
                $delimiteur = $m[1];
                continue;
            }
            if ($tampon === '' && ($net === '' || str_starts_with($net, '--'))) {
                continue;
            }
            $tampon .= $ligne;
            if (str_ends_with(rtrim($net), $delimiteur)) {
                $sql = substr(rtrim($tampon), 0, -strlen($delimiteur));
                $pdo->exec(self::sansDefiner($sql));
                $tampon = '';
                $n++;
            }
        }
        gzclose($gz);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        ParametreService::viderCache();
        NormalisationService::viderCache();
        return $n;
    }
}
