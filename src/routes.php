<?php
declare(strict_types=1);

use App\Controllers\AuthController as A;
use App\Controllers\InscriptionController as I;
use App\Controllers\LienController as L;
use App\Controllers\PersonneController as P;
use App\Core\Router;

return static function (Router $r): void {
    $r->post('/auth/otp/request', [A::class, 'demander'], false);
    $r->post('/auth/otp/verify', [A::class, 'verifier'], false);
    $r->post('/auth/logout', [A::class, 'deconnexion']);
    $r->get('/me', [A::class, 'moi']);

    $r->get('/branches', [I::class, 'branches']);
    $r->post('/inscriptions', [I::class, 'creer']);
    $r->post('/inscriptions/tiers', [I::class, 'creerTiers']);
    $r->post('/rapprochement/candidats', [I::class, 'rapprochement']);

    $r->get('/personnes/recherche', [P::class, 'recherche']);
    $r->get('/personnes/{id}', [P::class, 'voir']);
    $r->patch('/personnes/{id}', [P::class, 'modifier']);
    $r->post('/personnes/{id}/retrait', [P::class, 'retrait']);
    $r->get('/personnes/{id}/epouses', [P::class, 'epouses']);
    $r->post('/personnes/{id}/branche', [L::class, 'rattacher']);
    $r->post('/unions', [P::class, 'creerUnion']);

    $r->get('/mes-liens', [L::class, 'mesLiens']);
    $r->get('/liens/{id}', [L::class, 'voir']);
    $r->post('/liens/{id}/invitations', [L::class, 'inviter']);
    $r->post('/liens/{id}/valider', [L::class, 'valider']);
    $r->post('/liens/{id}/examen', [L::class, 'examen']);
    $r->post('/liens/{id}/prive', [L::class, 'prive']);
    $r->post('/liens/{id}/rejeter', [L::class, 'rejeter']);
    $r->get('/referent/file', [L::class, 'file']);
    $r->get('/confirmations/{token}', [L::class, 'lireConfirmation'], false);
    $r->post('/confirmations/{token}', [L::class, 'repondreConfirmation'], false);

    $r->get('/doublons', [L::class, 'doublons']);
    $r->post('/doublons/{id}/fusionner', [L::class, 'fusionnerDoublon']);
    $r->post('/doublons/{id}/distinct', [L::class, 'doublonDistinct']);
    $r->post('/fusions/{id}/annuler', [L::class, 'annulerFusion']);

    $r->get('/notifications', [L::class, 'notifications']);
    $r->post('/notifications/{id}/lu', [L::class, 'notificationLue']);
};
