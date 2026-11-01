<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function tenter(Request $req): void
    {
        $token = $req->bearer();
        if ($token === null) {
            return;
        }
        $hash = hash('sha256', $token);
        $u = Database::one(
            'SELECT u.*, s.expire_le AS session_expire_le FROM sessions_api s JOIN utilisateurs u ON u.id = s.utilisateur_id
             WHERE s.token_hash = ? AND s.revoque_le IS NULL AND s.expire_le > UTC_TIMESTAMP() AND u.actif = 1',
            [$hash]
        );
        if ($u !== null) {
            $req->user = $u;
            $req->tokenHash = $hash;
            // Session glissante : un membre qui utilise l'application n'a pas à refaire le parcours du code.
            if (strtotime($u['session_expire_le'] . ' UTC') < time() + (int) (TOKEN_TTL_DAYS / 2) * 86400) {
                Database::run('UPDATE sessions_api SET expire_le = ? WHERE token_hash = ?', [gmdate('Y-m-d H:i:s', time() + TOKEN_TTL_DAYS * 86400), $hash]);
            }
        }
    }

    public static function exiger(Request $req): void
    {
        self::tenter($req);
        if ($req->user === null) {
            throw new ApiException(401, 'non_authentifie', 'Connexion requise.');
        }
    }

    /** @return array{0: string, 1: int} jeton clair et id de session */
    public static function creerSession(int $utilisateurId, string $userAgent): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Database::insert('sessions_api', [
            'utilisateur_id' => $utilisateurId,
            'token_hash' => hash('sha256', $token),
            'expire_le' => gmdate('Y-m-d H:i:s', time() + TOKEN_TTL_DAYS * 86400),
            'user_agent' => $userAgent,
        ]);
        return [$token, $id];
    }

    public static function revoquer(string $tokenHash): void
    {
        Database::run('UPDATE sessions_api SET revoque_le = UTC_TIMESTAMP() WHERE token_hash = ?', [$tokenHash]);
    }
}
