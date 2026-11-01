<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\InscriptionService;

final class InscriptionController
{
    public static function branches(Request $r): void
    {
        Response::ok(Database::all('SELECT id, nom, description FROM branches WHERE actif = 1 ORDER BY ordre, nom'));
    }

    public static function creer(Request $r): void
    {
        $res = InscriptionService::pourSoi($r->user, $r->body);
        Response::ok($res, isset($res['rejoue']) ? 200 : 201);
    }

    public static function creerTiers(Request $r): void
    {
        $res = InscriptionService::pourTiers($r->user, $r->body);
        Response::ok($res, isset($res['rejoue']) ? 200 : 201);
    }

    public static function rapprochement(Request $r): void
    {
        RateLimiter::verifier('recherche', 'u' . $r->user['id'], RATE_RECHERCHE);
        $b = $r->body;
        $branche = isset($b['branche_id']) ? (int) $b['branche_id'] : null;
        Response::ok(InscriptionService::candidatsSerialises(InscriptionService::critere($b, $branche), $r->user));
    }
}
