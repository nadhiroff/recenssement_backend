<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Alertes système par le service Expo. Le contenu transite par Expo puis Apple ou Google :
 * le texte reste générique et n'identifie jamais une personne, le détail se lit dans l'application.
 */
final class PushService
{
    private const URL = 'https://exp.host/--/api/v2/push/send';

    public static function enregistrer(int $utilisateurId, string $token, string $plateforme): void
    {
        // Un téléphone sert un seul compte à la fois : le dernier connecté reçoit les alertes.
        Database::run(
            'INSERT INTO appareils_push (utilisateur_id, token, plateforme) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE utilisateur_id = VALUES(utilisateur_id), plateforme = VALUES(plateforme)',
            [$utilisateurId, $token, $plateforme]
        );
    }

    public static function oublier(int $utilisateurId, ?string $token = null): void
    {
        if ($token === null) {
            Database::run('DELETE FROM appareils_push WHERE utilisateur_id = ?', [$utilisateurId]);
        } else {
            Database::run('DELETE FROM appareils_push WHERE utilisateur_id = ? AND token = ?', [$utilisateurId, $token]);
        }
    }

    public static function envoyer(int $utilisateurId, string $corps, array $donnees): void
    {
        $tokens = array_column(Database::all('SELECT token FROM appareils_push WHERE utilisateur_id = ?', [$utilisateurId]), 'token');
        if ($tokens === []) {
            return;
        }
        $nonLues = (int) Database::value('SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lu_le IS NULL', [$utilisateurId]);
        $messages = array_map(static fn(string $t): array => [
            'to' => $t,
            'title' => APP_NAME,
            'body' => $corps,
            'data' => $donnees,
            'badge' => $nonLues,
            'sound' => 'default',
            'channelId' => 'alertes',
        ], $tokens);
        if (PUSH_DRIVER === 'log') {
            file_put_contents(BASE_PATH . '/storage/logs/push.log', gmdate('c') . ' ' . json_encode(['body' => $corps, 'data' => $donnees, 'n' => count($tokens)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            return;
        }
        $contexte = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($messages),
            'timeout' => 4,
            'ignore_errors' => true,
        ]]);
        $reponse = @file_get_contents(self::URL, false, $contexte);
        if ($reponse === false) {
            error_log('Alerte système non transmise');
            return;
        }
        // Un téléphone désinstallé ne doit plus être sollicité.
        foreach ((json_decode($reponse, true)['data'] ?? []) as $i => $ticket) {
            if (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered' && isset($tokens[$i])) {
                Database::run('DELETE FROM appareils_push WHERE token = ?', [$tokens[$i]]);
            }
        }
    }
}
