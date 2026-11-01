<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\PersonneRepository;
use App\Serializers\PersonneSerializer;
use App\Services\ConfirmationService;
use App\Services\DoublonService;
use App\Services\FusionService;
use App\Services\JournalService;
use App\Services\LienService;
use App\Services\PermissionService;

final class LienController
{
    /** Résumé d'un participant : une fiche masquée au lecteur ne révèle que son identifiant de lien. */
    private static function participant(?array $p, array $u, bool $referent): ?array
    {
        if ($p === null) {
            return null;
        }
        if ($referent) {
            return PersonneSerializer::selonRole($p, $u) ?? ['id' => (int) $p['id'], 'nom_complet' => $p['nom_complet'], 'est_privee' => (bool) $p['est_privee']];
        }
        return PersonneSerializer::selonRole($p, $u);
    }

    private static function vue(array $l, array $fiches, array $u, bool $referent): array
    {
        $vue = [
            'id' => (int) $l['id'],
            'role_parent' => $l['role_parent'],
            'statut' => $l['statut'],
            'declare_le' => $l['declare_le'],
            'parent' => self::participant($fiches[(int) $l['parent_id']] ?? null, $u, $referent),
            'enfant' => self::participant($fiches[(int) $l['enfant_id']] ?? null, $u, $referent),
        ];
        if ($l['statut'] === 'rejete') {
            $vue['motif_rejet'] = $l['motif_rejet'];
        }
        if ($referent) {
            $vue += [
                'motif_prive' => $l['motif_prive'],
                'branche_id' => $l['branche_id'] === null ? null : (int) $l['branche_id'],
                'confirme_le' => $l['confirme_le'],
                'valide_le' => $l['valide_le'],
                'conflit' => PermissionService::conflitInteret($u, $l),
            ];
        }
        return $vue;
    }

    public static function mesLiens(Request $r): void
    {
        $u = $r->user;
        $liens = Database::all(
            "SELECT l.*, e.branche_id FROM liens_parente l JOIN personnes e ON e.id = l.enfant_id
             WHERE l.statut <> 'prive' AND (l.declarant_utilisateur_id = ? OR l.enfant_id = ?)
             ORDER BY l.declare_le DESC LIMIT 100",
            [$u['id'], (int) $u['personne_id']]
        );
        $fiches = PersonneRepository::parIds(array_merge(array_column($liens, 'parent_id'), array_column($liens, 'enfant_id')));
        Response::ok(array_map(static fn(array $l): array => self::vue($l, $fiches, $u, false), $liens));
    }

    public static function voir(Request $r): void
    {
        $u = $r->user;
        $l = LienService::charger((int) $r->params['id']);
        $referent = PermissionService::couvreBranche($u, $l['branche_id'] === null ? null : (int) $l['branche_id']);
        $concerne = (int) $l['declarant_utilisateur_id'] === (int) $u['id'] || in_array((int) $u['personne_id'], [(int) $l['enfant_id'], (int) $l['parent_id']], true);
        if (!$referent && (!$concerne || $l['statut'] === 'prive')) {
            throw new ApiException(404, 'introuvable', 'Lien introuvable.');
        }
        $fiches = PersonneRepository::parIds([$l['parent_id'], $l['enfant_id']]);
        $vue = self::vue($l, $fiches, $u, $referent);
        if ($referent) {
            if ($l['statut'] === 'prive') {
                JournalService::ecrire((int) $u['id'], 'personne.consultation_privee', 'personne', (int) $l['enfant_id'], (int) $l['branche_id']);
            }
            $vue['declarant'] = $l['declarant_utilisateur_id'] === null ? null : self::nomUtilisateur((int) $l['declarant_utilisateur_id']);
            $vue['invitations'] = Database::all('SELECT created_at, utilise_le, reponse, expire_le FROM invitations_confirmation WHERE lien_id = ? ORDER BY id', [$l['id']]);
            $vue['historique'] = Database::all("SELECT action, details, cree_le FROM journal_actions WHERE entite_type = 'lien' AND entite_id = ? ORDER BY id", [$l['id']]);
        }
        Response::ok($vue);
    }

    private static function nomUtilisateur(int $id): ?string
    {
        $p = Database::value('SELECT personne_id FROM utilisateurs WHERE id = ?', [$id]);
        return $p === null ? null : (PersonneRepository::trouver((int) $p)['nom_complet'] ?? null);
    }

    public static function file(Request $r): void
    {
        $u = $r->user;
        PermissionService::exigerReferent($u);
        [, $parPage, $offset] = $r->page();
        $conds = [];
        $params = [];
        $statut = $r->query['statut'] ?? null;
        if ($statut !== null && $statut !== '') {
            Validator::choix($r->query, 'statut', array_keys(LienService::TRANSITIONS));
            $conds[] = 'l.statut = ?';
            $params[] = $statut;
        } else {
            $conds[] = "l.statut IN ('declare','confirme','en_examen')";
        }
        $branche = isset($r->query['branche']) && $r->query['branche'] !== '' ? (int) $r->query['branche'] : null;
        if (!PermissionService::estAdmin($u)) {
            $mes = PermissionService::branchesReferent($u);
            if ($branche !== null && !in_array($branche, $mes, true)) {
                throw new ApiException(403, 'interdit', 'Cette branche ne relève pas de vous.');
            }
            $conds[] = $mes === [] ? '1 = 0' : 'e.branche_id IN (' . implode(',', $mes) . ')';
        }
        if ($branche !== null) {
            $conds[] = 'e.branche_id = ?';
            $params[] = $branche;
        }
        $liens = Database::all(
            'SELECT l.*, e.branche_id FROM liens_parente l JOIN personnes e ON e.id = l.enfant_id WHERE ' . implode(' AND ', $conds)
            . " ORDER BY FIELD(l.statut, 'confirme', 'en_examen', 'declare'), l.declare_le LIMIT $parPage OFFSET $offset",
            $params
        );
        $fiches = PersonneRepository::parIds(array_merge(array_column($liens, 'parent_id'), array_column($liens, 'enfant_id')));
        Response::ok(array_map(static fn(array $l): array => self::vue($l, $fiches, $u, true), $liens));
    }

    public static function valider(Request $r): void
    {
        LienService::valider($r->user, (int) $r->params['id']);
        Response::ok(['statut' => 'valide']);
    }

    public static function examen(Request $r): void
    {
        LienService::examiner($r->user, (int) $r->params['id']);
        Response::ok(['statut' => 'en_examen']);
    }

    public static function prive(Request $r): void
    {
        LienService::rendrePrive($r->user, (int) $r->params['id'], $r->body);
        Response::ok(['statut' => 'prive']);
    }

    public static function rejeter(Request $r): void
    {
        LienService::rejeter($r->user, (int) $r->params['id'], $r->body);
        Response::ok(['statut' => 'rejete']);
    }

    public static function rattacher(Request $r): void
    {
        $u = $r->user;
        PermissionService::exigerReferent($u);
        $p = PersonneRepository::trouver((int) $r->params['id']);
        if ($p === null) {
            throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
        }
        $avant = $p['branche_id'] === null ? null : (int) $p['branche_id'];
        PermissionService::exigerBranche($u, $avant);
        $branche = Validator::entier($r->body, 'branche_id', true, 1);
        if (Database::value('SELECT id FROM branches WHERE id = ? AND actif = 1', [$branche]) === null) {
            Validator::echec('branche_id', 'Branche inconnue.');
        }
        Database::run('UPDATE personnes SET branche_id = ? WHERE id = ?', [$branche, $p['id']]);
        JournalService::ecrire((int) $u['id'], 'personne.rattachement', 'personne', (int) $p['id'], $branche, ['de' => $avant, 'vers' => $branche]);
        Response::ok(['branche_id' => $branche]);
    }

    public static function inviter(Request $r): void
    {
        Response::ok(ConfirmationService::inviter($r->user, (int) $r->params['id'], $r->body), 201);
    }

    public static function lireConfirmation(Request $r): void
    {
        Response::ok(ConfirmationService::lire($r->params['token']));
    }

    public static function repondreConfirmation(Request $r): void
    {
        $reponse = Validator::choix($r->body, 'reponse', ['oui', 'non', 'ne_sait_pas']);
        Response::ok(ConfirmationService::repondre($r->params['token'], $reponse, $r->user));
    }

    public static function doublons(Request $r): void
    {
        [, $parPage, $offset] = $r->page();
        $statut = Validator::choix($r->query, 'statut', ['a_traiter', 'fusionne', 'distinct'], 'a_traiter');
        Response::ok(DoublonService::liste($r->user, $statut, $parPage, $offset));
    }

    public static function fusionnerDoublon(Request $r): void
    {
        PermissionService::exigerReferent($r->user);
        $conservee = Validator::entier($r->body, 'personne_conservee_id', true, 1);
        Response::ok(['fusion_id' => DoublonService::fusionner($r->user, (int) $r->params['id'], $conservee)]);
    }

    public static function doublonDistinct(Request $r): void
    {
        PermissionService::exigerReferent($r->user);
        DoublonService::distinct($r->user, (int) $r->params['id']);
        Response::ok(['statut' => 'distinct']);
    }

    public static function annulerFusion(Request $r): void
    {
        PermissionService::exigerAdmin($r->user);
        FusionService::annuler($r->user, (int) $r->params['id']);
        Response::ok(['annulee' => true]);
    }

    public static function notifications(Request $r): void
    {
        Response::ok(Database::all(
            'SELECT id, type, message, lien_id, lu_le, created_at FROM notifications WHERE utilisateur_id = ? ORDER BY id DESC LIMIT 50',
            [$r->user['id']]
        ));
    }

    public static function toutLu(Request $r): void
    {
        Database::run('UPDATE notifications SET lu_le = UTC_TIMESTAMP() WHERE utilisateur_id = ? AND lu_le IS NULL', [$r->user['id']]);
        Response::ok(['lu' => true]);
    }

    public static function notificationLue(Request $r): void
    {
        Database::run('UPDATE notifications SET lu_le = UTC_TIMESTAMP() WHERE id = ? AND utilisateur_id = ? AND lu_le IS NULL', [(int) $r->params['id'], $r->user['id']]);
        Response::ok(['lu' => true]);
    }
}
