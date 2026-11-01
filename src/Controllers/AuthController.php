<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\PersonneRepository;
use App\Serializers\PersonneSerializer;
use App\Services\AuthService;
use App\Services\JournalService;
use App\Services\OtpService;
use App\Services\PermissionService;
use App\Services\PushService;

final class AuthController
{
    public static function demander(Request $r): void
    {
        $tel = Validator::telephone($r->body, 'telephone');
        $email = Validator::email($r->body, 'email', false);
        RateLimiter::verifier('otp_ip', (string) Request::ip(), RATE_OTP_IP);
        RateLimiter::verifier('otp_tel', $tel, RATE_OTP_TELEPHONE);
        $destination = AuthService::demanderCode($tel, $email);
        Response::ok(['envoye' => true, 'canal' => OtpService::canal(), 'destination' => $destination, 'expire_dans' => OTP_TTL_SECONDS]);
    }

    public static function verifier(Request $r): void
    {
        $tel = Validator::telephone($r->body, 'telephone');
        $code = Validator::texte($r->body, 'code', 6);
        RateLimiter::verifier('otp_ip', (string) Request::ip(), RATE_OTP_IP);
        Response::ok(AuthService::verifierCode($tel, $code, $r->userAgent()));
    }

    public static function deconnexion(Request $r): void
    {
        $appareil = $r->body['push_token'] ?? null;
        if (is_string($appareil)) {
            PushService::oublier((int) $r->user['id'], $appareil);
        }
        Auth::revoquer($r->tokenHash);
        JournalService::ecrire((int) $r->user['id'], 'auth.deconnexion', 'utilisateur', (int) $r->user['id']);
        Response::ok(['deconnecte' => true]);
    }

    public static function enregistrerAppareil(Request $r): void
    {
        $token = Validator::texte($r->body, 'token', 190);
        if (!preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', (string) $token)) {
            Validator::echec('token', 'Jeton d\'appareil invalide.');
        }
        PushService::enregistrer((int) $r->user['id'], (string) $token, Validator::choix($r->body, 'plateforme', ['ios', 'android']));
        Response::ok(['enregistre' => true]);
    }

    public static function demanderEmail(Request $r): void
    {
        RateLimiter::verifier('otp_tel', 'email' . $r->user['id'], RATE_OTP_TELEPHONE);
        $email = Validator::email($r->body, 'email');
        AuthService::demanderChangementEmail($r->user, $email);
        Response::ok(['destination' => AuthService::masquer($email)]);
    }

    public static function confirmerEmail(Request $r): void
    {
        $code = Validator::texte($r->body, 'code', 6);
        Response::ok(['email' => AuthService::confirmerChangementEmail($r->user, $code)]);
    }

    public static function moi(Request $r): void
    {
        $u = $r->user;
        $fiche = $u['personne_id'] === null ? null : PersonneRepository::trouver((int) $u['personne_id']);
        Response::ok([
            'id' => (int) $u['id'],
            'role' => $u['role_global'],
            'telephone' => $u['telephone_whatsapp'],
            'email' => $u['email'],
            'personne' => $fiche === null ? null : PersonneSerializer::pourProprietaire($fiche),
            'branches_referent' => PermissionService::branchesReferent($u),
            'branches_relais' => PermissionService::branchesRelais($u),
            'notifications_non_lues' => (int) Database::value('SELECT COUNT(*) FROM notifications WHERE utilisateur_id = ? AND lu_le IS NULL', [$u['id']]),
        ]);
    }
}
