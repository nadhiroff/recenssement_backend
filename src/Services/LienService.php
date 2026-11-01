<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Validator;
use App\Repositories\LienRepository;
use App\Repositories\PersonneRepository;

final class LienService
{
    public const TRANSITIONS = [
        'declare' => ['confirme', 'en_examen', 'prive', 'rejete'],
        'confirme' => ['valide', 'en_examen', 'prive', 'rejete'],
        'en_examen' => ['valide', 'prive', 'rejete'],
        'prive' => ['en_examen', 'rejete'],
        'valide' => ['en_examen', 'prive', 'rejete'],
        'rejete' => [],
    ];

    public static function transitionAutorisee(string $de, string $vers): bool
    {
        return in_array($vers, self::TRANSITIONS[$de] ?? [], true);
    }

    public static function charger(int $id): array
    {
        $lien = LienRepository::trouver($id);
        if ($lien === null) {
            throw new ApiException(404, 'introuvable', 'Lien introuvable.');
        }
        return $lien;
    }

    private static function brancheDe(array $lien): ?int
    {
        return $lien['branche_id'] === null ? null : (int) $lien['branche_id'];
    }

    /** Droit de décider sur ce lien : branche couverte et aucun conflit d'intérêts. */
    private static function exigerDecideur(array $u, array $lien): void
    {
        PermissionService::exigerReferent($u);
        PermissionService::exigerBranche($u, self::brancheDe($lien));
        if (PermissionService::conflitInteret($u, $lien)) {
            self::orienter($lien, $u);
            PermissionService::exigerSansConflit($u, $lien);
        }
    }

    /** Oriente le lien vers les autres référents de la branche, ou à défaut vers l'administrateur. */
    private static function orienter(array $lien, array $u): void
    {
        $autres = array_filter(
            Database::all(
                'SELECT u.id, u.personne_id FROM referents_branche r JOIN utilisateurs u ON u.id = r.utilisateur_id
                 WHERE r.branche_id = ? AND u.id <> ? AND u.actif = 1',
                [$lien['branche_id'], $u['id']]
            ),
            static fn(array $a): bool => !PermissionService::conflitInteret($a, $lien)
        );
        if ($autres === []) {
            $autres = array_filter(
                Database::all("SELECT id, personne_id FROM utilisateurs WHERE role_global = 'admin' AND actif = 1 AND id <> ?", [$u['id']]),
                static fn(array $a): bool => !PermissionService::conflitInteret($a, $lien)
            );
        }
        foreach ($autres as $a) {
            $deja = Database::value("SELECT id FROM notifications WHERE utilisateur_id = ? AND lien_id = ? AND type = 'orientation' AND lu_le IS NULL", [$a['id'], $lien['id']]);
            if ($deja === null) {
                NotificationService::notifier((int) $a['id'], 'orientation', 'Un lien de parenté vous est orienté : un autre référent ne peut pas le traiter.', (int) $lien['id'], false);
            }
        }
    }

    private static function transiter(array $lien, string $vers, ?int $auteur, array $champs, string $action, array $details = []): void
    {
        if (!self::transitionAutorisee($lien['statut'], $vers)) {
            throw new ApiException(409, 'transition_interdite', "Passage de « {$lien['statut']} » à « $vers » non autorisé.");
        }
        Database::tx(static function () use ($lien, $vers, $auteur, $champs, $action, $details): void {
            $n = Database::update('liens_parente', ['statut' => $vers] + $champs, 'id = ? AND statut = ?', [$lien['id'], $lien['statut']]);
            if ($n !== 1) {
                throw new ApiException(409, 'lien_modifie', 'Ce lien vient d\'être traité par quelqu\'un d\'autre.');
            }
            JournalService::ecrire($auteur, $action, 'lien', (int) $lien['id'], self::brancheDe($lien), ['de' => $lien['statut'], 'vers' => $vers] + $details);
            if ($lien['statut'] === 'prive') {
                self::leverMasque((int) $lien['enfant_id']);
            }
        });
    }

    /** La fiche redevient visible quand plus aucun lien privé ne la concerne. */
    private static function leverMasque(int $enfantId): void
    {
        if (Database::value("SELECT id FROM liens_parente WHERE enfant_id = ? AND statut = 'prive' LIMIT 1", [$enfantId]) === null) {
            Database::run('UPDATE personnes SET est_privee = 0 WHERE id = ?', [$enfantId]);
        }
    }

    private static function nomsDuLien(array $lien): array
    {
        $f = PersonneRepository::parIds([$lien['parent_id'], $lien['enfant_id']]);
        return [$f[(int) $lien['parent_id']]['nom_complet'] ?? '', $f[(int) $lien['enfant_id']]['nom_complet'] ?? ''];
    }

    private static function notifierDeclarant(array $lien, string $type, string $message): void
    {
        if ($lien['declarant_utilisateur_id'] !== null) {
            NotificationService::notifier((int) $lien['declarant_utilisateur_id'], $type, $message, (int) $lien['id']);
        }
    }

    public static function valider(array $u, int $id): void
    {
        $lien = self::charger($id);
        self::exigerDecideur($u, $lien);
        Database::tx(static function () use ($lien, $u): void {
            self::transiter($lien, 'valide', (int) $u['id'], ['valide_par_utilisateur_id' => $u['id'], 'valide_le' => gmdate('Y-m-d H:i:s')], 'lien.validation');
            self::validerUnion((int) $lien['enfant_id']);
        });
        [$parent, $enfant] = self::nomsDuLien($lien);
        self::notifierDeclarant($lien, 'lien_valide', "Le lien entre $parent et $enfant a été validé.");
    }

    /** Quand père et mère d'un enfant sont validés, leur union l'est aussi. */
    private static function validerUnion(int $enfantId): void
    {
        $liens = Database::all("SELECT parent_id, role_parent FROM liens_parente WHERE enfant_id = ? AND statut = 'valide'", [$enfantId]);
        $parents = array_column($liens, 'parent_id', 'role_parent');
        if (isset($parents['pere'], $parents['mere'])) {
            Database::run(
                "UPDATE unions SET statut = 'valide' WHERE epoux_id = ? AND epouse_id = ? AND statut NOT IN ('prive','rejete')",
                [$parents['pere'], $parents['mere']]
            );
        }
    }

    public static function examiner(array $u, int $id): void
    {
        $lien = self::charger($id);
        self::exigerDecideur($u, $lien);
        self::transiter($lien, 'en_examen', (int) $u['id'], [], 'lien.examen');
    }

    public static function rendrePrive(array $u, int $id, array $b): void
    {
        $lien = self::charger($id);
        self::exigerDecideur($u, $lien);
        $motif = Validator::texte($b, 'motif', 500);
        Database::tx(static function () use ($lien, $u, $motif): void {
            self::transiter($lien, 'prive', (int) $u['id'], ['motif_prive' => $motif], 'lien.prive');
            Database::run('UPDATE personnes SET est_privee = 1 WHERE id = ?', [$lien['enfant_id']]);
        });
    }

    public static function rejeter(array $u, int $id, array $b): void
    {
        $lien = self::charger($id);
        self::exigerDecideur($u, $lien);
        $motif = Validator::texte($b, 'motif', 500);
        self::transiter($lien, 'rejete', (int) $u['id'], ['motif_rejet' => $motif], 'lien.rejet');
        [$parent, $enfant] = self::nomsDuLien($lien);
        self::notifierDeclarant($lien, 'lien_rejete', "Le lien entre $parent et $enfant n'a pas été retenu : $motif");
    }

    /** Réponse d'un proche à une invitation. */
    public static function confirmer(array $lien, ?int $utilisateurId): void
    {
        self::transiter($lien, 'confirme', $utilisateurId, ['confirme_par_utilisateur_id' => $utilisateurId, 'confirme_le' => gmdate('Y-m-d H:i:s')], 'lien.confirmation');
    }

    public static function contester(array $lien, ?int $utilisateurId): void
    {
        self::transiter($lien, 'en_examen', $utilisateurId, [], 'lien.contestation');
    }

    /** Référents de la branche et membres concernés relancés par la tâche planifiée. @return int relances envoyées */
    public static function relancer(): int
    {
        $delai = ParametreService::entier('delai_relance_jours', 60);
        $suivant = ParametreService::entier('delai_relance_suivante_jours', 30);
        $max = ParametreService::entier('nb_relances_max', 3);
        $liens = Database::all(
            "SELECT * FROM liens_parente WHERE statut = 'declare' AND declarant_utilisateur_id IS NOT NULL AND nb_relances < ?
             AND ((derniere_relance_le IS NULL AND declare_le <= UTC_TIMESTAMP() - INTERVAL ? DAY)
               OR derniere_relance_le <= UTC_TIMESTAMP() - INTERVAL ? DAY)",
            [$max, $delai, $suivant]
        );
        foreach ($liens as $l) {
            [$parent, $enfant] = self::nomsDuLien($l);
            NotificationService::notifier(
                (int) $l['declarant_utilisateur_id'],
                'relance',
                "Le lien entre $parent et $enfant attend toujours la confirmation d'un proche. Invitez un frère ou une sœur à le confirmer.",
                (int) $l['id']
            );
            Database::run('UPDATE liens_parente SET derniere_relance_le = UTC_TIMESTAMP(), nb_relances = nb_relances + 1 WHERE id = ?', [$l['id']]);
            JournalService::ecrire(null, 'lien.relance', 'lien', (int) $l['id']);
        }
        return count($liens);
    }
}
