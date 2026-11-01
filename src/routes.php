<?php
declare(strict_types=1);

use App\Controllers\AuthController as A;
use App\Controllers\InscriptionController as I;
use App\Controllers\LienController as L;
use App\Controllers\PersonneController as P;
use App\Controllers\SuiviController as S;
use App\Core\Router;

return static function (Router $r): void {
    $r->post('/auth/otp/request', [A::class, 'demander'], false);
    $r->post('/auth/otp/verify', [A::class, 'verifier'], false);
    $r->post('/auth/logout', [A::class, 'deconnexion']);
    $r->get('/me', [A::class, 'moi']);
    $r->post('/me/appareils', [A::class, 'enregistrerAppareil']);
    $r->post('/me/email', [A::class, 'demanderEmail']);
    $r->post('/me/email/confirmer', [A::class, 'confirmerEmail']);

    $r->get('/branches', [I::class, 'branches']);
    $r->post('/inscriptions', [I::class, 'creer']);
    $r->post('/inscriptions/tiers', [I::class, 'creerTiers']);
    $r->post('/rapprochement/candidats', [I::class, 'rapprochement']);

    $r->get('/personnes/recherche', [P::class, 'recherche']);
    $r->get('/personnes/{id}', [P::class, 'voir']);
    $r->patch('/personnes/{id}', [P::class, 'modifier']);
    $r->post('/personnes/{id}/retrait', [P::class, 'retrait']);
    $r->get('/personnes/{id}/epouses', [P::class, 'epouses']);
    $r->post('/personnes/{id}/epouses/ordre', [P::class, 'ordonnerEpouses']);
    $r->get('/personnes/{id}/famille', [P::class, 'famille']);
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
    $r->post('/notifications/lu', [L::class, 'toutLu']);
    $r->post('/notifications/{id}/lu', [L::class, 'notificationLue']);

    $r->get('/arbre', [S::class, 'arbre']);
    $r->get('/stats', [S::class, 'stats']);
    $r->get('/journal', [S::class, 'journal']);
    $r->get('/export/gedcom', [S::class, 'gedcom']);
    $r->get('/export/csv', [S::class, 'csv']);
    $r->get('/taches', [S::class, 'taches']);
    $r->post('/taches/{nom}/lancer', [S::class, 'lancerTache']);

    $r->post('/admin/branches', [S::class, 'creerBranche']);
    $r->patch('/admin/branches/{id}', [S::class, 'modifierBranche']);
    $r->get('/admin/utilisateurs', [S::class, 'utilisateurs']);
    $r->post('/admin/utilisateurs/{id}/role', [S::class, 'definirRole']);
    $r->get('/admin/variantes', [S::class, 'variantes']);
    $r->post('/admin/variantes', [S::class, 'creerVariante']);
    $r->post('/admin/variantes/recalculer', [S::class, 'recalculer']);
    $r->patch('/admin/variantes/{id}', [S::class, 'modifierVariante']);
    $r->delete('/admin/variantes/{id}', [S::class, 'supprimerVariante']);
    $r->get('/admin/parametres', [S::class, 'parametres']);
    $r->patch('/admin/parametres', [S::class, 'modifierParametres']);
};
