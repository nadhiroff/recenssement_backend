<?php
declare(strict_types=1);

// Le panneau est réservé à l'administrateur général : email, mot de passe, puis code en production.
use App\Core\ApiException;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Validator;
use App\Services\JournalService;
use App\Services\OtpService;

if ($u !== null) {
    rediriger('tableau');
}

$ouvrirSession = static function (int $id): never {
    session_regenerate_id(true);
    unset($_SESSION['otp_identifiant'], $_SESSION['otp_utilisateur']);
    $_SESSION['utilisateur_id'] = $id;
    Database::run('UPDATE utilisateurs SET derniere_connexion = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    JournalService::ecrire($id, 'auth.connexion', 'utilisateur', $id, null, ['canal' => 'web']);
    rediriger('tableau');
};

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
                throw new ApiException(401, 'identifiants', 'Email ou mot de passe incorrect.');
            }
            if (!ADMIN_OTP_REQUIS) {
                $ouvrirSession((int) $cible['id']);
            }
            OtpService::emettre($email, $email, 'email');
            $_SESSION['otp_identifiant'] = $email;
            $_SESSION['otp_utilisateur'] = (int) $cible['id'];
            $etape = 'code';
        } elseif ($mode === 'code') {
            OtpService::verifier((string) ($_SESSION['otp_identifiant'] ?? ''), (string) ($_POST['code'] ?? ''));
            $ouvrirSession((int) ($_SESSION['otp_utilisateur'] ?? 0));
        } elseif ($mode === 'recommencer') {
            unset($_SESSION['otp_identifiant'], $_SESSION['otp_utilisateur']);
            $etape = 'identifiants';
        }
    }
} catch (ApiException $ex) {
    $erreur = $ex->getMessage();
}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="assets/admin.css"></head>
<body class="page-connexion">
<main class="acces">
  <div class="marque"><div class="losange" aria-hidden="true"></div><div><strong>Recensement ZOCLI</strong><span>Espace d'administration</span></div></div>
  <?php if ($etape === 'code') { ?>
  <h1>Code de connexion</h1>
  <p class="muet">Saisissez le code à 6 chiffres reçu par email.</p>
  <?php if ($erreur !== null) { echo '<div class="alerte erreur" role="alert">' . e($erreur) . '</div>'; } ?>
  <form method="post" action="<?= e(url('connexion')) ?>"><?= champCsrf() ?><input type="hidden" name="mode" value="code">
    <label for="code">Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
    <button>Vérifier</button></form>
  <form method="post" action="<?= e(url('connexion')) ?>"><?= champCsrf() ?><input type="hidden" name="mode" value="recommencer"><button class="lien">Recommencer</button></form>
  <?php } else { ?>
  <h1>Connexion</h1>
  <?php if ($erreur !== null) { echo '<div class="alerte erreur" role="alert">' . e($erreur) . '</div>'; } ?>
  <form method="post" action="<?= e(url('connexion')) ?>"><?= champCsrf() ?><input type="hidden" name="mode" value="admin">
    <label for="email">Email</label><input id="email" type="email" name="email" autocomplete="username" required autofocus>
    <label for="mdp">Mot de passe</label><input id="mdp" type="password" name="mot_de_passe" autocomplete="current-password" required>
    <button>Se connecter</button></form>
  <?php } ?>
</main>
</body></html>
