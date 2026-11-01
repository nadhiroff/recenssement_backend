<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;

final class PermissionService
{
    public static function estAdmin(array $u): bool
    {
        return $u['role_global'] === 'admin';
    }

    public static function estReferent(array $u): bool
    {
        return in_array($u['role_global'], ['referent', 'admin'], true);
    }

    public static function exigerAdmin(array $u): void
    {
        if (!self::estAdmin($u)) {
            throw new ApiException(403, 'interdit', 'Réservé à l\'administrateur général.');
        }
    }

    public static function exigerReferent(array $u): void
    {
        if (!self::estReferent($u)) {
            throw new ApiException(403, 'interdit', 'Réservé aux référents.');
        }
    }

    /** @return int[] */
    public static function branchesReferent(array $u): array
    {
        return Database::ids('SELECT branche_id FROM referents_branche WHERE utilisateur_id = ?', [$u['id']]);
    }

    /** @return int[] */
    public static function branchesRelais(array $u): array
    {
        return Database::ids('SELECT branche_id FROM relais_branche WHERE utilisateur_id = ?', [$u['id']]);
    }

    /** Référent de cette branche, ou administrateur. */
    public static function couvreBranche(array $u, ?int $brancheId): bool
    {
        if (self::estAdmin($u)) {
            return true;
        }
        return $u['role_global'] === 'referent' && $brancheId !== null && in_array($brancheId, self::branchesReferent($u), true);
    }

    public static function exigerBranche(array $u, ?int $brancheId): void
    {
        if (!self::couvreBranche($u, $brancheId)) {
            throw new ApiException(403, 'interdit', 'Cette branche ne relève pas de vous.');
        }
    }

    /**
     * Conflit d'intérêts : le lien concerne l'utilisateur, ou l'un de ses enfants
     * (toute personne dont il est parent par un lien non rejeté). L'admin y est soumis aussi.
     */
    public static function conflitInteret(array $u, array $lien): bool
    {
        $moi = $u['personne_id'] === null ? null : (int) $u['personne_id'];
        if ($moi === null) {
            return false;
        }
        $concernes = [(int) $lien['parent_id'], (int) $lien['enfant_id']];
        if (in_array($moi, $concernes, true)) {
            return true;
        }
        $enfants = Database::ids("SELECT enfant_id FROM liens_parente WHERE parent_id = ? AND statut <> 'rejete'", [$moi]);
        return array_intersect($concernes, $enfants) !== [];
    }

    public static function exigerSansConflit(array $u, array $lien): void
    {
        if (self::conflitInteret($u, $lien)) {
            throw new ApiException(403, 'conflit_interet', 'Ce lien vous concerne : il doit être traité par un autre référent ou l\'administrateur.');
        }
    }
}
