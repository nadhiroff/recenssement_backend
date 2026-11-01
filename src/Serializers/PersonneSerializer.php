<?php
declare(strict_types=1);

namespace App\Serializers;

use App\Services\PermissionService;

/**
 * Seule porte de sortie des fiches : aucun contrôleur ne renvoie une ligne SQL brute.
 * Attend une fiche hydratée par PersonneRepository (clés noms et nom_complet).
 */
final class PersonneSerializer
{
    private const CHAMPS_PUBLICS = ['id', 'nom_complet', 'noms', 'sexe', 'annee_naissance', 'precision_naissance',
        'annee_deces', 'precision_deces', 'est_decede', 'branche_id', 'est_fondateur'];

    public static function estMasquee(array $p): bool
    {
        return (bool) $p['est_privee'] || $p['statut_fiche'] !== 'active' || $p['fusionnee_dans_id'] !== null;
    }

    public static function pourMembre(array $p): ?array
    {
        if (self::estMasquee($p)) {
            return null;
        }
        $s = array_intersect_key($p, array_flip(self::CHAMPS_PUBLICS));
        $s['noms'] = array_map(static fn(array $n): array => ['type' => $n['type'], 'valeur' => $n['valeur']], $p['noms']);
        return self::typer($s);
    }

    /** Sa propre fiche : le membre revoit ses coordonnées, même si la fiche est privée. */
    public static function pourProprietaire(array $p): array
    {
        $s = array_intersect_key($p, array_flip(self::CHAMPS_PUBLICS));
        $s['noms'] = $p['noms'];
        $s['telephone_whatsapp'] = $p['telephone_whatsapp'];
        $s['email'] = $p['email'];
        $s['lieu_residence'] = $p['lieu_residence'];
        $s['statut_fiche'] = $p['statut_fiche'];
        return self::typer($s);
    }

    public static function pourReferent(array $p): array
    {
        $s = array_intersect_key($p, array_flip(self::CHAMPS_PUBLICS));
        $s['noms'] = $p['noms'];
        $s += [
            'telephone_whatsapp' => $p['telephone_whatsapp'],
            'email' => $p['email'],
            'lieu_residence' => $p['lieu_residence'],
            'est_privee' => (bool) $p['est_privee'],
            'statut_fiche' => $p['statut_fiche'],
            'fusionnee_dans_id' => $p['fusionnee_dans_id'],
        ];
        return self::typer($s);
    }

    public static function pourAdmin(array $p): array
    {
        return self::pourReferent($p) + ['created_at' => $p['created_at'], 'cree_par_utilisateur_id' => $p['cree_par_utilisateur_id']];
    }

    /** Choisit le sérialiseur selon le rôle réel de l'utilisateur vis-à-vis de cette fiche. */
    public static function selonRole(array $p, array $u): ?array
    {
        if (PermissionService::estAdmin($u)) {
            return self::pourAdmin($p);
        }
        if (PermissionService::couvreBranche($u, $p['branche_id'] === null ? null : (int) $p['branche_id'])) {
            return self::pourReferent($p);
        }
        if ($u['personne_id'] !== null && (int) $u['personne_id'] === (int) $p['id']) {
            return self::pourProprietaire($p);
        }
        return self::pourMembre($p);
    }

    /** Fiche proposée au rapprochement mais masquée : l'existence seule est signalée. */
    public static function anonyme(): array
    {
        return ['anonyme' => true];
    }

    private static function typer(array $s): array
    {
        foreach (['est_decede', 'est_fondateur'] as $c) {
            $s[$c] = (bool) $s[$c];
        }
        return $s;
    }
}
