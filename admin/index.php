<?php
declare(strict_types=1);

use App\Core\Response;
use App\Services\TacheService;

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

Response::securite();
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
demarrerSession();

$page = (string) ($_GET['p'] ?? 'tableau');
$pages = ['connexion', 'deconnexion', 'tableau', 'branches', 'utilisateurs', 'file', 'lien', 'doublons', 'foyers', 'fusions', 'variantes', 'parametres', 'journal', 'exports', 'taches'];
if (!in_array($page, $pages, true)) {
    http_response_code(404);
    exit('Page inconnue.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();
}

$u = utilisateurCourant();
if ($page !== 'connexion' && $u === null) {
    rediriger('connexion');
}
require __DIR__ . "/pages/$page.php";

// Pseudo-cron pour les hébergeurs sans cron : après la réponse, jamais avant.
if ($u !== null && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    session_write_close();
    TacheService::rattraper();
}
