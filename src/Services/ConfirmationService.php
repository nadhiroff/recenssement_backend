<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Validator;
use App\Repositories\LienRepository;
use App\Repositories\PersonneRepository;

final class ConfirmationService
{
    /** Le déclarant (ou l'enfant concerné) obtient une URL wa.me à ouvrir lui-même. */
    public static function inviter(array $u, int $lienId, array $b): array
    {
        $lien = LienService::charger($lienId);
        $concerne = (int) $lien['declarant_utilisateur_id'] === (int) $u['id'] || (int) $lien['enfant_id'] === (int) $u['personne_id'];
        if (!$concerne || $lien['statut'] === 'prive') {
            throw new ApiException(404, 'introuvable', 'Lien introuvable.');
        }
        if ($lien['statut'] !== 'declare') {
            throw new ApiException(409, 'deja_traite', 'Ce lien n\'attend plus de confirmation.');
        }
        $telephone = Validator::telephone($b, 'telephone', false);
        // Aucun lien sur la seule parole d'une personne : le proche ne peut pas être le déclarant.
        if ($telephone !== null && $telephone === $u['telephone_whatsapp']) {
            Validator::echec('telephone', 'Invitez un proche : vous ne pouvez pas vous inviter vous-même.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Database::insert('invitations_confirmation', [
            'lien_id' => $lienId,
            'token_hash' => hash('sha256', $token),
            'telephone_invite' => $telephone,
            'cree_par' => $u['id'],
            'expire_le' => gmdate('Y-m-d H:i:s', time() + INVITATION_TTL_DAYS * 86400),
        ]);
        JournalService::ecrire((int) $u['id'], 'lien.invitation', 'lien', $lienId, $lien['branche_id'] === null ? null : (int) $lien['branche_id'], ['invitation' => $id]);

        $url = APP_URL . '/confirmation.php?t=' . $token;
        $noms = self::noms($lien);
        $texte = "Bonjour, je recense la famille ZOCLI. Peux-tu confirmer que {$noms['parent']} est bien {$noms['role']} de {$noms['enfant']} ? Il suffit d'ouvrir ce lien : $url";
        $numero = $telephone === null ? '' : ltrim($telephone, '+');
        return ['url' => $url, 'whatsapp_url' => "https://wa.me/$numero?text=" . rawurlencode($texte), 'expire_dans_jours' => INVITATION_TTL_DAYS];
    }

    private static function noms(array $lien): array
    {
        $f = PersonneRepository::parIds([$lien['parent_id'], $lien['enfant_id']]);
        return [
            'parent' => $f[(int) $lien['parent_id']]['nom_complet'] ?? '',
            'enfant' => $f[(int) $lien['enfant_id']]['nom_complet'] ?? '',
            'role' => $lien['role_parent'] === 'pere' ? 'le père' : 'la mère',
        ];
    }

    private static function invitation(string $token): array
    {
        RateLimiter::verifier('confirmation_ip', (string) Request::ip(), RATE_CONFIRMATION_IP);
        $inv = Database::one('SELECT * FROM invitations_confirmation WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($inv === null) {
            throw new ApiException(404, 'invitation_inconnue', 'Cette invitation n\'existe pas.');
        }
        if ($inv['expire_le'] < gmdate('Y-m-d H:i:s')) {
            throw new ApiException(410, 'invitation_expiree', 'Cette invitation a expiré.');
        }
        return $inv;
    }

    /** Ce que voit le proche : deux noms et une question, rien d'autre. */
    public static function lire(string $token): array
    {
        $inv = self::invitation($token);
        $lien = LienService::charger((int) $inv['lien_id']);
        if ($lien['statut'] === 'prive') {
            throw new ApiException(404, 'invitation_inconnue', 'Cette invitation n\'existe pas.');
        }
        $repondu = $inv['utilise_le'] !== null;
        return self::noms($lien) + ['deja_repondu' => $repondu, 'statut_attendu' => !$repondu && $lien['statut'] === 'declare'];
    }

    public static function repondre(string $token, string $reponse, ?array $u): array
    {
        $inv = self::invitation($token);
        $lien = LienService::charger((int) $inv['lien_id']);
        if ($lien['statut'] === 'prive') {
            throw new ApiException(404, 'invitation_inconnue', 'Cette invitation n\'existe pas.');
        }
        if ($inv['utilise_le'] !== null) {
            throw new ApiException(409, 'deja_repondu', 'Cette invitation a déjà été utilisée.');
        }
        $userId = $u === null ? null : (int) $u['id'];
        if ($userId !== null && $userId === (int) $lien['declarant_utilisateur_id']) {
            throw new ApiException(403, 'auto_confirmation', 'Vous ne pouvez pas confirmer un lien que vous avez déclaré.');
        }
        return Database::tx(static function () use ($inv, $lien, $reponse, $userId): array {
            $n = Database::update(
                'invitations_confirmation',
                ['utilise_le' => gmdate('Y-m-d H:i:s'), 'reponse' => ['oui' => 'confirme', 'non' => 'refuse'][$reponse] ?? null],
                'id = ? AND utilise_le IS NULL',
                [$inv['id']]
            );
            if ($n !== 1) {
                throw new ApiException(409, 'deja_repondu', 'Cette invitation a déjà été utilisée.');
            }
            JournalService::ecrire($userId, 'invitation.reponse', 'lien', (int) $lien['id'], $lien['branche_id'] === null ? null : (int) $lien['branche_id'], ['reponse' => $reponse]);
            if ($lien['statut'] === 'declare' && $reponse === 'oui') {
                LienService::confirmer($lien, $userId);
            } elseif ($lien['statut'] === 'declare' && $reponse === 'non') {
                LienService::contester($lien, $userId);
            }
            return ['enregistre' => true];
        });
    }
}
