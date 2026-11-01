<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Validator;
use App\Repositories\PersonneRepository;

/** Opérations d'administration, communes à l'API et au panneau web. */
final class AdminService
{
    public static function enregistrerBranche(array $u, array $d, ?int $id = null): int
    {
        $champs = [
            'nom' => Validator::texte($d, 'nom', 120),
            'description' => Validator::texte($d, 'description', 500, false),
            'ordre' => Validator::entier($d, 'ordre', false, 0, 9999) ?? 0,
            'ancetre_personne_id' => Validator::entier($d, 'ancetre_personne_id', false, 1),
            'actif' => Validator::booleen($d, 'actif', true) ? 1 : 0,
        ];
        if ($champs['ancetre_personne_id'] !== null && PersonneRepository::trouver($champs['ancetre_personne_id']) === null) {
            Validator::echec('ancetre_personne_id', 'Fiche introuvable.');
        }
        try {
            if ($id === null) {
                $id = Database::insert('branches', $champs);
            } elseif (Database::update('branches', $champs, 'id = ?', [$id]) === 0 && Database::value('SELECT id FROM branches WHERE id = ?', [$id]) === null) {
                throw new ApiException(404, 'introuvable', 'Branche introuvable.');
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Validator::echec('nom', 'Une branche porte déjà ce nom.');
            }
            throw $e;
        }
        JournalService::ecrire((int) $u['id'], 'branche.enregistrement', 'branche', $id, $id, ['nom' => $champs['nom']]);
        return $id;
    }

    public static function fondateur(): ?array
    {
        $id = Database::value('SELECT id FROM personnes WHERE est_fondateur = 1 AND fusionnee_dans_id IS NULL LIMIT 1');
        return $id === null ? null : PersonneRepository::trouver((int) $id);
    }

    public static function enregistrerFondateur(array $u, array $d): int
    {
        return Database::tx(static function () use ($u, $d): int {
            $noms = [
                ['type' => 'nom_famille', 'valeur' => Validator::texte($d, 'nom_famille')],
                ['type' => 'naissance', 'valeur' => Validator::texte($d, 'prenoms')],
            ];
            $annee = Validator::annee($d, 'annee_naissance');
            $champs = [
                'sexe' => Validator::choix($d, 'sexe', ['M', 'F'], 'M'),
                'annee_naissance' => $annee,
                'precision_naissance' => $annee === null ? 'inconnue' : Validator::choix($d, 'precision_naissance', ['exacte', 'environ', 'decennie'], 'environ'),
                'est_decede' => 1,
                'est_fondateur' => 1,
            ];
            $f = self::fondateur();
            if ($f !== null) {
                Database::update('personnes', $champs, 'id = ?', [$f['id']]);
                $autres = array_filter($f['noms'], static fn(array $n): bool => !in_array($n['type'], ['nom_famille', 'naissance'], true));
                PersonneRepository::remplacerNoms((int) $f['id'], [...$noms, ...array_values($autres)]);
                $id = (int) $f['id'];
            } else {
                $id = PersonneRepository::creer($champs, $noms, (int) $u['id']);
            }
            JournalService::ecrire((int) $u['id'], 'fondateur.enregistrement', 'personne', $id);
            return $id;
        });
    }

    /** @param int[] $branchesReferent @param int[] $branchesRelais */
    public static function definirRole(array $u, int $cibleId, string $role, array $branchesReferent, array $branchesRelais): void
    {
        if (!in_array($role, ['membre', 'relais', 'referent', 'admin'], true)) {
            Validator::echec('role', 'Rôle invalide.');
        }
        $cible = Database::one('SELECT id, role_global FROM utilisateurs WHERE id = ?', [$cibleId]);
        if ($cible === null) {
            throw new ApiException(404, 'introuvable', 'Utilisateur introuvable.');
        }
        if ($cible['role_global'] === 'admin' && $role !== 'admin'
            && (int) Database::value("SELECT COUNT(*) FROM utilisateurs WHERE role_global = 'admin' AND actif = 1") <= 1) {
            throw new ApiException(409, 'dernier_admin', 'Il doit rester au moins un administrateur.');
        }
        Database::tx(static function () use ($u, $cibleId, $role, $branchesReferent, $branchesRelais, $cible): void {
            Database::run('UPDATE utilisateurs SET role_global = ? WHERE id = ?', [$role, $cibleId]);
            Database::run('DELETE FROM referents_branche WHERE utilisateur_id = ?', [$cibleId]);
            Database::run('DELETE FROM relais_branche WHERE utilisateur_id = ?', [$cibleId]);
            foreach (array_unique(array_map('intval', $role === 'referent' ? $branchesReferent : [])) as $b) {
                Database::insert('referents_branche', ['utilisateur_id' => $cibleId, 'branche_id' => $b]);
            }
            foreach (array_unique(array_map('intval', $role === 'relais' ? $branchesRelais : [])) as $b) {
                Database::insert('relais_branche', ['utilisateur_id' => $cibleId, 'branche_id' => $b]);
            }
            JournalService::ecrire((int) $u['id'], 'role.changement', 'utilisateur', $cibleId, null, ['de' => $cible['role_global'], 'vers' => $role]);
        });
    }

    /** Rétablit l'accès d'un membre qui a perdu son numéro ou son email : vérifier son identité d'abord. */
    public static function modifierCompte(array $u, int $cibleId, array $d): void
    {
        $cible = Database::one('SELECT id, personne_id, telephone_whatsapp FROM utilisateurs WHERE id = ?', [$cibleId]);
        if ($cible === null) {
            throw new ApiException(404, 'introuvable', 'Utilisateur introuvable.');
        }
        $champs = ['email' => Validator::email($d, 'email', false), 'telephone_whatsapp' => Validator::telephone($d, 'telephone', false)];
        try {
            Database::tx(static function () use ($u, $cible, $cibleId, $champs): void {
                Database::update('utilisateurs', $champs, 'id = ?', [$cibleId]);
                if ($cible['personne_id'] !== null) {
                    Database::update('personnes', $champs, 'id = ?', [$cible['personne_id']]);
                }
                Database::run('UPDATE sessions_api SET revoque_le = UTC_TIMESTAMP() WHERE utilisateur_id = ? AND revoque_le IS NULL', [$cibleId]);
                JournalService::ecrire((int) $u['id'], 'compte.modification', 'utilisateur', $cibleId);
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Validator::echec('telephone', 'Ce numéro est déjà utilisé par un autre compte.');
            }
            throw $e;
        }
    }

    /** Annule un retrait : le compte et sa fiche reviennent, le consentement reste à redonner hors application. */
    public static function reactiverCompte(array $u, int $cibleId): void
    {
        Database::tx(static function () use ($u, $cibleId): void {
            Database::run('UPDATE utilisateurs SET actif = 1 WHERE id = ?', [$cibleId]);
            Database::run(
                "UPDATE personnes p JOIN utilisateurs c ON c.personne_id = p.id SET p.statut_fiche = 'active',
                 p.telephone_whatsapp = c.telephone_whatsapp, p.email = c.email WHERE c.id = ?",
                [$cibleId]
            );
            JournalService::ecrire((int) $u['id'], 'compte.reactivation', 'utilisateur', $cibleId);
        });
    }

    /** @return array[] branches qui n'ont pas exactement deux référents */
    public static function branchesSansDeuxReferents(): array
    {
        return Database::all(
            'SELECT b.id, b.nom, COUNT(r.id) AS nb FROM branches b LEFT JOIN referents_branche r ON r.branche_id = b.id
             WHERE b.actif = 1 GROUP BY b.id, b.nom HAVING nb <> 2 ORDER BY b.ordre'
        );
    }

    public static function enregistrerVariante(array $u, array $d, ?int $id = null): int
    {
        $variante = NormalisationService::sansAccents(Validator::texte($d, 'variante', 80));
        $forme = NormalisationService::sansAccents(Validator::texte($d, 'forme_canonique', 80));
        if ($variante === $forme) {
            Validator::echec('variante', 'La variante doit différer de la forme canonique.');
        }
        $champs = ['variante' => $variante, 'forme_canonique' => $forme, 'actif' => Validator::booleen($d, 'actif', true) ? 1 : 0];
        try {
            if ($id === null) {
                $id = Database::insert('variantes_noms', $champs);
            } else {
                Database::update('variantes_noms', $champs, 'id = ?', [$id]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Validator::echec('variante', 'Cette variante existe déjà.');
            }
            throw $e;
        }
        NormalisationService::viderCache();
        JournalService::ecrire((int) $u['id'], 'variante.enregistrement', 'variante', $id, null, $champs);
        return $id;
    }

    public static function supprimerVariante(array $u, int $id): void
    {
        Database::run('DELETE FROM variantes_noms WHERE id = ?', [$id]);
        NormalisationService::viderCache();
        JournalService::ecrire((int) $u['id'], 'variante.suppression', 'variante', $id);
    }

    public static function recalculerNoms(array $u): int
    {
        $n = NormalisationService::recalculerTout();
        JournalService::ecrire((int) $u['id'], 'variante.recalcul', null, null, null, ['noms' => $n]);
        return $n;
    }

    public static function modifierParametres(array $u, array $valeurs): void
    {
        $connus = array_keys(ParametreService::tous());
        Database::tx(static function () use ($u, $valeurs, $connus): void {
            foreach ($valeurs as $cle => $valeur) {
                if (!in_array($cle, $connus, true)) {
                    Validator::echec((string) $cle, 'Paramètre inconnu.');
                }
                if (filter_var($valeur, FILTER_VALIDATE_INT) === false) {
                    Validator::echec((string) $cle, 'Valeur entière attendue.');
                }
                Database::run('UPDATE parametres SET valeur = ? WHERE cle = ?', [(string) (int) $valeur, $cle]);
                JournalService::ecrire((int) $u['id'], 'parametre.changement', 'parametre', null, null, ['cle' => $cle, 'valeur' => (int) $valeur]);
            }
        });
        ParametreService::viderCache();
    }
}
