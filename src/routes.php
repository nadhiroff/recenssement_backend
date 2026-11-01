<?php
declare(strict_types=1);

use App\Controllers\AuthController as A;
use App\Controllers\InscriptionController as I;
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
    $r->post('/unions', [P::class, 'creerUnion']);
};
