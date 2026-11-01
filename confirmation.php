<?php
declare(strict_types=1);

// Page légère ouverte depuis le lien WhatsApp : aucun compte requis, le jeton fait foi.
use App\Core\ApiException;
use App\Core\Response;
use App\Services\ConfirmationService;

require __DIR__ . '/src/bootstrap.php';

Response::securite();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
header('Content-Type: text/html; charset=utf-8');

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$token = (string) ($_GET['t'] ?? '');
$message = null;
$infos = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $reponse = (string) ($_POST['reponse'] ?? '');
        if (!in_array($reponse, ['oui', 'non', 'ne_sait_pas'], true)) {
            throw new ApiException(422, 'validation', 'Réponse invalide.');
        }
        ConfirmationService::repondre($token, $reponse, null);
        $message = 'Merci, votre réponse est enregistrée.';
    } else {
        $infos = ConfirmationService::lire($token);
        if ($infos['deja_repondu']) {
            $message = 'Cette invitation a déjà reçu une réponse. Merci.';
        } elseif (!$infos['statut_attendu']) {
            $message = 'Ce lien a déjà été traité. Merci.';
        }
    }
} catch (ApiException $ex) {
    http_response_code($ex->http);
    $message = $ex->getMessage();
}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Confirmation — Recensement ZOCLI</title>
<style>
:root{color-scheme:light dark;--f:#FAF6EF;--c:#fff;--t:#1E2420;--p:#1F6B4F;--b:#E3DCCF}
@media(prefers-color-scheme:dark){:root{--f:#121614;--c:#1C2320;--t:#ECEFEA;--p:#3FA37C;--b:#303A35}}
body{margin:0;background:var(--f);color:var(--t);font:17px/1.5 system-ui,sans-serif;padding:16px}
main{max-width:480px;margin:24px auto;background:var(--c);border-radius:16px;padding:24px}
h1{font-size:22px;color:var(--p);margin:0 0 12px}
button{display:block;width:100%;min-height:52px;font:600 17px system-ui;border-radius:14px;margin-top:12px;border:1.5px solid var(--p);background:transparent;color:var(--p)}
button.oui{background:var(--p);color:#fff}
</style></head><body><main>
<h1>Famille ZOCLI</h1>
<?php if ($message !== null): ?>
<p><?= $e($message) ?></p>
<?php else: ?>
<p>Confirmez-vous que <strong><?= $e($infos['parent']) ?></strong> est <?= $e($infos['role']) ?> de <strong><?= $e($infos['enfant']) ?></strong> ?</p>
<form method="post" action="confirmation.php?t=<?= $e(rawurlencode($token)) ?>">
<button class="oui" name="reponse" value="oui">Oui</button>
<button name="reponse" value="non">Non</button>
<button name="reponse" value="ne_sait_pas">Je ne sais pas</button>
</form>
<?php endif; ?>
</main></body></html>
