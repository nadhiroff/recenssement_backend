<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \App\Services\JournalService::ecrire((int) $u['id'], 'auth.deconnexion', 'utilisateur', (int) $u['id']);
    $_SESSION = [];
    session_destroy();
}
rediriger('connexion');
