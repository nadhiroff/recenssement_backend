<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class NotificationService
{
    /** Textes des alertes système : sans nom, car ils transitent par des services tiers. */
    private const TEXTES_PUSH = [
        'lien_confirme' => 'Un proche a confirmé un de vos liens.',
        'lien_valide' => 'Un de vos liens a été validé.',
        'lien_rejete' => 'Un de vos liens n\'a pas été retenu.',
        'relance' => 'Un de vos liens attend la confirmation d\'un proche.',
        'a_traiter' => 'Un lien attend votre décision.',
        'orientation' => 'Un lien vous est orienté.',
        'revendication' => 'Une fiche est à vérifier.',
    ];

    public static function notifier(int $utilisateurId, string $type, string $message, ?int $lienId = null, bool $email = true): void
    {
        Database::insert('notifications', [
            'utilisateur_id' => $utilisateurId,
            'type' => $type,
            'message' => $message,
            'lien_id' => $lienId,
        ]);
        PushService::envoyer($utilisateurId, self::TEXTES_PUSH[$type] ?? 'Du nouveau dans l\'application.', ['type' => $type, 'lien_id' => $lienId]);
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
