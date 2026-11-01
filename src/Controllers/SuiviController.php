<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AdminService;
use App\Services\ArbreService;
use App\Services\CsvExporter;
use App\Services\GedcomExporter;
use App\Services\JournalService;
use App\Services\ParametreService;
use App\Services\PermissionService;
use App\Services\SuiviService;
use App\Services\TacheService;

final class SuiviController
{
    public static function arbre(Request $r): void
    {
        $q = $r->query;
        Response::ok(ArbreService::construire(
            $r->user,
            ($q['branche'] ?? '') === '' ? null : (int) $q['branche'],
            ($q['racine'] ?? '') === '' ? null : (int) $q['racine'],
            (int) ($q['profondeur'] ?? 2)
        ));
    }

    private static function branchesLisibles(array $u): ?array
    {
        return PermissionService::estAdmin($u) ? null : PermissionService::branchesReferent($u);
    }

    public static function stats(Request $r): void
    {
        PermissionService::exigerReferent($r->user);
        Response::ok(SuiviService::stats(self::branchesLisibles($r->user)));
    }

    public static function journal(Request $r): void
    {
        PermissionService::exigerReferent($r->user);
        [, $parPage, $offset] = $r->page();
        Response::ok(SuiviService::journal(self::branchesLisibles($r->user), $r->query, $parPage, $offset));
    }

    public static function gedcom(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        JournalService::ecrire((int) $r->user['id'], 'export.gedcom');
        Response::telechargement(GedcomExporter::generer(), 'recensement_zocli.ged', 'text/plain; charset=utf-8');
    }

    public static function csv(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        JournalService::ecrire((int) $r->user['id'], 'export.csv');
        Response::telechargement(CsvExporter::fiches(), 'fiches_zocli.csv', 'text/csv; charset=utf-8');
    }

    public static function taches(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(TacheService::etats());
    }

    public static function lancerTache(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        $res = TacheService::lancer($r->params['nom']);
        JournalService::ecrire((int) $r->user['id'], 'tache.lancement_manuel', 'tache', null, null, ['nom' => $r->params['nom']]);
        Response::ok($res);
    }

    public static function creerBranche(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(['id' => AdminService::enregistrerBranche($r->user, $r->body)], 201);
    }

    public static function modifierBranche(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(['id' => AdminService::enregistrerBranche($r->user, $r->body, (int) $r->params['id'])]);
    }

    public static function utilisateurs(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        [, $parPage, $offset] = $r->page();
        Response::ok(Database::all(
            "SELECT id, personne_id, telephone_whatsapp, email, role_global, actif, derniere_connexion FROM utilisateurs ORDER BY id LIMIT $parPage OFFSET $offset"
        ));
    }

    public static function definirRole(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        AdminService::definirRole(
            $r->user,
            (int) $r->params['id'],
            (string) ($r->body['role'] ?? ''),
            (array) ($r->body['branches_referent'] ?? []),
            (array) ($r->body['branches_relais'] ?? [])
        );
        Response::ok(['role' => $r->body['role']]);
    }

    public static function variantes(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(Database::all('SELECT id, variante, forme_canonique, actif FROM variantes_noms ORDER BY forme_canonique, variante'));
    }

    public static function creerVariante(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(['id' => AdminService::enregistrerVariante($r->user, $r->body)], 201);
    }

    public static function modifierVariante(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(['id' => AdminService::enregistrerVariante($r->user, $r->body, (int) $r->params['id'])]);
    }

    public static function supprimerVariante(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        AdminService::supprimerVariante($r->user, (int) $r->params['id']);
        Response::ok(['supprimee' => true]);
    }

    public static function recalculer(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(['noms' => AdminService::recalculerNoms($r->user)]);
    }

    public static function parametres(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        Response::ok(Database::all('SELECT cle, valeur, description FROM parametres ORDER BY cle'));
    }

    public static function modifierParametres(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        AdminService::modifierParametres($r->user, $r->body);
        Response::ok(ParametreService::tous());
    }
}
