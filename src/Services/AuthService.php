<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Auth;
use App\Core\Database;

final class AuthService
{
    public static function demanderCode(string $telephone, ?string $email): void
    {
        $canal = OtpService::canal();
        $u = Database::one('SELECT id, email, actif FROM utilisateurs WHERE telephone_whatsapp = ?', [$telephone]);
        if ($u !== null && !(int) $u['actif']) {
            return; // réponse identique : ne pas révéler l'état du compte
        }
        if ($canal === 'whatsapp') {
            OtpService::emettre($telephone, $telephone, $canal, $email);
            return;
        }
        // Un email fourni ne remplace jamais celui d'un compte existant : le code part vers l'adresse enregistrée.
        $dest = $u['email'] ?? $email;
        if ($dest === null) {
            throw new ApiException(422, 'email_requis', 'Indiquez une adresse email pour recevoir votre code.', ['champ' => 'email']);
        }
        OtpService::emettre($telephone, $dest, $canal, $u === null || $u['email'] === null ? $dest : null);
    }

    /** @return array{token:string, utilisateur:array} */
    public static function verifierCode(string $telephone, string $code, string $userAgent): array
    {
        $otp = OtpService::verifier($telephone, $code);
        return Database::tx(static function () use ($telephone, $otp, $userAgent): array {
            $u = Database::one('SELECT * FROM utilisateurs WHERE telephone_whatsapp = ?', [$telephone]);
            $email = $otp['email_destination'];
            if ($u === null) {
                $id = Database::insert('utilisateurs', ['telephone_whatsapp' => $telephone, 'email' => $email]);
                $u = Database::one('SELECT * FROM utilisateurs WHERE id = ?', [$id]);
            } elseif ($u['email'] === null && $email !== null) {
                Database::run('UPDATE utilisateurs SET email = ? WHERE id = ?', [$email, $u['id']]);
            }
            if (!(int) $u['actif']) {
                throw new ApiException(403, 'compte_inactif', 'Ce compte est désactivé.');
            }
            self::rattacherFiche($u, $telephone, $email, $otp['canal']);
            Database::run('UPDATE utilisateurs SET derniere_connexion = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
            [$token] = Auth::creerSession((int) $u['id'], $userAgent);
            JournalService::ecrire((int) $u['id'], 'auth.connexion', 'utilisateur', (int) $u['id']);
            return ['token' => $token, 'utilisateur' => ['id' => (int) $u['id'], 'role' => $u['role_global']]];
        });
    }

    /**
     * Relie un compte neuf à la fiche créée pour lui par un relais.
     * Tant que le code part par email, le numéro seul ne prouve rien : l'email de la fiche doit aussi correspondre.
     */
    private static function rattacherFiche(array $u, string $telephone, ?string $email, string $canal): void
    {
        if ($u['personne_id'] !== null) {
            return;
        }
        $fiche = Database::one(
            "SELECT id, email FROM personnes WHERE telephone_whatsapp = ? AND fusionnee_dans_id IS NULL AND statut_fiche = 'active'
             AND NOT EXISTS (SELECT 1 FROM utilisateurs x WHERE x.personne_id = personnes.id)",
            [$telephone]
        );
        if ($fiche !== null && ($canal === 'whatsapp' || ($email !== null && $fiche['email'] === $email))) {
            Database::run('UPDATE utilisateurs SET personne_id = ? WHERE id = ?', [$fiche['id'], $u['id']]);
        }
    }
}
