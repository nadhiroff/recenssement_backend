<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;

/** Exécution unique des tâches planifiées : cron, bouton de l'admin et pseudo-cron passent ici. */
final class TacheService
{
    /** Période minimale entre deux exécutions du pseudo-cron, en secondes. */
    public const PERIODES = ['detect_doublons' => 604800, 'relances' => 86400, 'backup' => 86400];

    public static function lancer(string $nom): array
    {
        if (!isset(self::PERIODES[$nom])) {
            throw new ApiException(404, 'tache_inconnue', 'Tâche inconnue.');
        }
        $verrou = fopen(BASE_PATH . "/storage/locks/$nom.lock", 'c');
        if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
            throw new ApiException(409, 'tache_en_cours', 'Cette tâche est déjà en cours.');
        }
        try {
            $resultat = match ($nom) {
                'detect_doublons' => MatchingService::detecterDoublons(),
                'relances' => ['relances' => LienService::relancer()],
                'backup' => BackupService::sauvegarder(),
            };
            self::noter($nom, 'ok', json_encode($resultat, JSON_UNESCAPED_UNICODE));
            JournalService::ecrire(null, 'tache.execution', 'tache', null, null, ['nom' => $nom] + $resultat);
            return $resultat;
        } catch (\Throwable $e) {
            self::noter($nom, 'erreur', mb_substr($e->getMessage(), 0, 400));
            throw $e;
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }

    private static function noter(string $nom, string $statut, string $resume): void
    {
        Database::run(
            'INSERT INTO taches_execution (nom, derniere_execution, statut, resume) VALUES (?, UTC_TIMESTAMP(), ?, ?)
             ON DUPLICATE KEY UPDATE derniere_execution = VALUES(derniere_execution), statut = VALUES(statut), resume = VALUES(resume)',
            [$nom, $statut, $resume]
        );
    }

    public static function etats(): array
    {
        $lignes = array_column(Database::all('SELECT * FROM taches_execution'), null, 'nom');
        $res = [];
        foreach (array_keys(self::PERIODES) as $nom) {
            $res[] = $lignes[$nom] ?? ['nom' => $nom, 'derniere_execution' => null, 'statut' => null, 'resume' => null];
        }
        return $res;
    }

    /** Pseudo-cron : au plus une tâche en retard par requête admin, pour les hébergeurs sans cron. */
    public static function rattraper(): ?string
    {
        foreach (self::etats() as $e) {
            $derniere = $e['derniere_execution'] === null ? 0 : (int) strtotime($e['derniere_execution'] . ' UTC');
            if (time() - $derniere >= self::PERIODES[$e['nom']]) {
                try {
                    self::lancer($e['nom']);
                } catch (\Throwable $t) {
                    error_log('Pseudo-cron ' . $e['nom'] . ' : ' . $t->getMessage());
                }
                return $e['nom'];
            }
        }
        return null;
    }
}
