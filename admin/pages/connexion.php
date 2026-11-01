<?php
declare(strict_types=1);

// Admin : email + mot de passe + code. Référent : numéro + code (il n'a pas de mot de passe).
use App\Core\ApiException;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\JournalService;
use App\Services\OtpService;
use App\Services\PermissionService;

if ($u !== null) {
    rediriger('tableau');
}

$erreur = null;
$etape = isset($_SESSION['otp_identifiant']) ? 'code' : 'identifiants';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        RateLimiter::verifier('login_admin', (string) Request::ip(), RATE_LOGIN_ADMIN);
        $mode = (string) ($_POST['mode'] ?? '');
        if ($mode === 'admin') {
            $email = Validator::email($_POST, 'email');
            $cible = Database::one("SELECT * FROM utilisateurs WHERE email = ? AND role_global = 'admin' AND actif = 1 AND mot_de_passe_hash IS NOT NULL", [$email]);
            // Vérification à temps constant, même pour un email inconnu.
            $hash = $cible['mot_de_passe_hash'] ?? '$2y$12$usesomesillystringforsaltuZ1cFtYeJb9bG3s2k8pB3wWfFhVmG';
            if (!password_verify((string) ($_POST['mot_de_passe'] ?? ''), $hash) || $cible === null) {
                throw new ApiException(401, 'identifiants', 'Identifiants incorrects.');
            }
            OtpService::emettre($email, $email, 'email');
            $_SESSION['otp_identifiant'] = $email;
            $_SESSION['otp_utilisateur'] = (int) $cible['id'];
            $etape = 'code';
        } elseif ($mode === 'referent') {
            $tel = Validator::telephone($_POST, 'telephone');
            $cible = Database::one("SELECT * FROM utilisateurs WHERE telephone_whatsapp = ? AND role_global IN ('referent','admin') AND actif = 1", [$tel]);
            if ($cible !== null) {
                AuthService::demanderCode($tel, null);
            }
            // Même réponse que le numéro soit connu ou non.
            $_SESSION['otp_identifiant'] = $tel;
            $_SESSION['otp_utilisateur'] = $cible === null ? 0 : (int) $cible['id'];
            $etape = 'code';
        } elseif ($mode === 'code') {
            $identifiant = (string) ($_SESSION['otp_identifiant'] ?? '');
            OtpService::verifier($identifiant, (string) ($_POST['code'] ?? ''));
            $id = (int) ($_SESSION['otp_utilisateur'] ?? 0);
            $cible = Database::one('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1', [$id]);
            if ($cible === null || !PermissionService::estReferent($cible)) {
                throw new ApiException(401, 'code_invalide', 'Code incorrect ou expiré.');
            }
            session_regenerate_id(true);
            unset($_SESSION['otp_identifiant'], $_SESSION['otp_utilisateur']);
            $_SESSION['utilisateur_id'] = $id;
            Database::run('UPDATE utilisateurs SET derniere_connexion = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            JournalService::ecrire($id, 'auth.connexion', 'utilisateur', $id, null, ['canal' => 'web']);
            rediriger('tableau');
        } elseif ($mode === 'recommencer') {
            unset($_SESSION['otp_identifiant'], $_SESSION['otp_utilisateur']);
            $etape = 'identifiants';
        }
    }
} catch (ApiException $ex) {
    $erreur = $ex->getMessage();
}

haut('Connexion', null);
echo '<div class="connexion">';
if ($erreur !== null) {
    echo '<div class="alerte erreur" role="alert">' . e($erreur) . '</div>';
}
if ($etape === 'code') { ?>
<div class="carte"><h1>Code de connexion</h1>
<p class="muet">Saisissez le code à 6 chiffres reçu par email.</p>
<form method="post" action="<?= e(url('connexion')) ?>"><?= champCsrf() ?><input type="hidden" name="mode" value="code">
<label for="code">Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
<p><button>Vérifier</button></p></form>
<form method="post" action="<?= e(url('connexion')) ?>"><?= champCsrf() ?><input type="hidden" name="mode" value="recommencer"><button class="contour">Recommencer</button></form>
</div>
<?php } else { ?>
<div class="carte"><h1>Administrateur</h1>
<form method="post" action="<?= e(url('connexion')) ?>"><?= champCsrf() ?><input type="hidden" name="mode" value="admin">
<label for="email">Email</label><input id="email" type="email" name="email" autocomplete="username" required>
<label for="mdp">Mot de passe</label><input id="mdp" type="password" name="mot_de_passe" autocomplete="current-password" required>
<p><button>Continuer</button></p></form></div>
<div class="carte"><h2>Référent</h2>
<form method="post" action="<?= e(url('connexion')) ?>"><?= champCsrf() ?><input type="hidden" name="mode" value="referent">
<label for="tel">Numéro WhatsApp</label><input id="tel" name="telephone" value="+22901" autocomplete="tel" required>
<p><button class="contour">Recevoir mon code</button></p></form></div>
<?php }
echo '</div>';
bas();
