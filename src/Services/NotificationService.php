<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class NotificationService
{
    public static function notifier(int $utilisateurId, string $type, string $message, ?int $lienId = null, bool $email = true): void
    {
        Database::insert('notifications', [
            'utilisateur_id' => $utilisateurId,
            'type' => $type,
            'message' => $message,
            'lien_id' => $lienId,
        ]);
        if (!$email) {
            return;
        }
        $dest = Database::value('SELECT email FROM utilisateurs WHERE id = ? AND actif = 1', [$utilisateurId]);
        if ($dest !== null) {
            $corps = '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p><p>Ouvrez l\'application pour en savoir plus.</p>';
            MailService::envoyer($dest, 'Recensement ZOCLI : du nouveau', 'Du nouveau sur vos liens', $corps);
        }
    }
}
