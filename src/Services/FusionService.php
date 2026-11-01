<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Repositories\LienRepository;

/**
 * Une fusion ne supprime rien : la fiche absorbée pointe vers la conservée et tout ce qui la
 * concernait est transféré, avec un instantané complet permettant de tout restituer à l'identique.
 */
final class FusionService
{
    /** Quand un même parent est déclaré deux fois, le statut le plus élevé l'emporte. */
    private const RANG_STATUT = ['prive' => 5, 'valide' => 4, 'confirme' => 3, 'en_examen' => 2, 'declare' => 1];

    /** Conflit d'intérêts étendu aux fusions : personne ne fusionne sa propre fiche ni celle de ses enfants. */
    public static function exigerSansConflit(array $u, array $personneIds): void
    {
        foreach ($personneIds as $id) {
            if (PermissionService::conflitInteret($u, ['parent_id' => $id, 'enfant_id' => $id])) {
                throw new ApiException(403, 'conflit_interet', 'Cette paire vous concerne : elle doit être traitée par un autre référent ou l\'administrateur.');
            }
        }
    }

    private static function instantane(int $a, int $b): array
    {
        $ids = [$a, $b];
        $p = '?,?';
        return [
            'personnes' => Database::all("SELECT * FROM personnes WHERE id IN ($p)", $ids),
            'liens_parente' => array_map(static function (array $l): array {
                unset($l['enfant_actif_id']);
                return $l;
            }, Database::all("SELECT * FROM liens_parente WHERE parent_id IN ($p) OR enfant_id IN ($p)", [...$ids, ...$ids])),
            'unions' => Database::all("SELECT * FROM unions WHERE epoux_id IN ($p) OR epouse_id IN ($p)", [...$ids, ...$ids]),
            'noms_personne' => Database::all("SELECT * FROM noms_personne WHERE personne_id IN ($p)", $ids),
            'declarations_tiers' => Database::all("SELECT * FROM declarations_tiers WHERE personne_id IN ($p)", $ids),
            'consentements' => Database::all("SELECT * FROM consentements WHERE personne_id IN ($p)", $ids),
            'utilisateurs' => Database::all("SELECT id, personne_id, updated_at FROM utilisateurs WHERE personne_id IN ($p)", $ids),
            'branches' => Database::all("SELECT id, ancetre_personne_id, updated_at FROM branches WHERE ancetre_personne_id IN ($p)", $ids),
            'doublons_suspects' => Database::all("SELECT id, statut, traite_par, traite_le, updated_at FROM doublons_suspects WHERE personne_a_id IN ($p) OR personne_b_id IN ($p)", [...$ids, ...$ids]),
        ];
    }

    private static function verifier(array $avant, int $conservee, int $absorbee): void
    {
        foreach ($avant['liens_parente'] as $l) {
            if ($l['statut'] === 'rejete') {
                continue;
            }
            $paire = [(int) $l['parent_id'], (int) $l['enfant_id']];
            if (in_array($conservee, $paire, true) && in_array($absorbee, $paire, true)) {
                throw new ApiException(409, 'fusion_impossible', 'Ces deux fiches sont parent et enfant l\'une de l\'autre.');
            }
        }
        foreach ($avant['liens_parente'] as $l) {
            if ($l['statut'] !== 'rejete' && (int) $l['enfant_id'] === $absorbee && LienRepository::estDescendant((int) $l['parent_id'], $conservee)) {
                throw new ApiException(409, 'fusion_impossible', 'Cette fusion créerait une boucle dans l\'arbre.');
            }
        }
    }

    public static function fusionner(array $u, int $conservee, int $absorbee, ?int $doublonId = null): int
    {
        if ($conservee === $absorbee) {
            throw new ApiException(422, 'validation', 'Fiches identiques.');
        }
        return Database::tx(static function () use ($u, $conservee, $absorbee, $doublonId): int {
            $avant = self::instantane($conservee, $absorbee);
            if (count($avant['personnes']) !== 2) {
                throw new ApiException(404, 'introuvable', 'Fiche introuvable.');
            }
            foreach ($avant['personnes'] as $p) {
                if ($p['fusionnee_dans_id'] !== null) {
                    throw new ApiException(409, 'deja_fusionnee', 'Une de ces fiches a déjà été fusionnée.');
                }
            }
            self::verifier($avant, $conservee, $absorbee);

            $maintenant = gmdate('Y-m-d H:i:s');
            $fusionId = Database::insert('fusions', [
                'personne_conservee_id' => $conservee,
                'personne_absorbee_id' => $absorbee,
                'doublon_id' => $doublonId,
                'effectuee_par' => $u['id'],
                'effectuee_le' => $maintenant,
                'snapshot_avant' => json_encode($avant, JSON_UNESCAPED_UNICODE),
            ]);
            self::transfererLiens($avant, $conservee, $absorbee, $fusionId);
            self::transfererUnions($avant, $conservee, $absorbee);
            foreach (['noms_personne', 'declarations_tiers', 'consentements'] as $table) {
                Database::run("UPDATE `$table` SET personne_id = ? WHERE personne_id = ?", [$conservee, $absorbee]);
            }
            Database::run('UPDATE noms_personne SET principal = 0 WHERE id IN (' . self::idsNoms($avant, $absorbee) . ')');
            Database::run('UPDATE utilisateurs SET personne_id = ? WHERE personne_id = ?', [$conservee, $absorbee]);
            Database::run('UPDATE branches SET ancetre_personne_id = ? WHERE ancetre_personne_id = ?', [$conservee, $absorbee]);
            Database::run(
                "UPDATE doublons_suspects SET statut = 'fusionne', traite_par = ?, traite_le = ?
                 WHERE statut = 'a_traiter' AND (personne_a_id = ? OR personne_b_id = ?)",
                [$u['id'], $maintenant, $absorbee, $absorbee]
            );
            self::completerFiche($avant, $conservee, $absorbee);
            Database::run('UPDATE personnes SET fusionnee_dans_id = ? WHERE id = ?', [$conservee, $absorbee]);
            JournalService::ecrire((int) $u['id'], 'fusion.effectuee', 'personne', $conservee, self::branche($avant, $conservee), ['absorbee' => $absorbee, 'fusion' => $fusionId]);
            return $fusionId;
        });
    }

    private static function branche(array $avant, int $id): ?int
    {
        foreach ($avant['personnes'] as $p) {
            if ((int) $p['id'] === $id && $p['branche_id'] !== null) {
                return (int) $p['branche_id'];
            }
        }
        return null;
    }

    private static function idsNoms(array $avant, int $absorbee): string
    {
        $ids = [0];
        foreach ($avant['noms_personne'] as $n) {
            if ((int) $n['personne_id'] === $absorbee) {
                $ids[] = (int) $n['id'];
            }
        }
        return implode(',', $ids);
    }

    private static function completerFiche(array $avant, int $conservee, int $absorbee): void
    {
        $par = array_column($avant['personnes'], null, 'id');
        $champs = [];
        foreach (['annee_naissance', 'telephone_whatsapp', 'email', 'lieu_residence', 'annee_deces'] as $c) {
            if ($par[$conservee][$c] === null && $par[$absorbee][$c] !== null) {
                $champs[$c] = $par[$absorbee][$c];
                if ($c === 'annee_naissance') {
                    $champs['precision_naissance'] = $par[$absorbee]['precision_naissance'];
                    $champs['mois_naissance'] = $par[$absorbee]['mois_naissance'];
                    $champs['jour_naissance'] = $par[$absorbee]['jour_naissance'];
                }
            }
        }
        if ($par[$absorbee]['est_privee']) {
            $champs['est_privee'] = 1;
        }
        if ($par[$absorbee]['est_decede']) {
            $champs['est_decede'] = 1;
        }
        if ($champs !== []) {
            Database::update('personnes', $champs, 'id = ?', [$conservee]);
        }
    }

    private static function transfererLiens(array $avant, int $conservee, int $absorbee, int $fusionId): void
    {
        $actifs = [];
        foreach ($avant['liens_parente'] as $l) {
            if ((int) $l['enfant_id'] === $conservee && $l['statut'] !== 'rejete') {
                $actifs[$l['role_parent']] = $l;
            }
        }
        $motif = "Doublon retiré par la fusion n° $fusionId";
        foreach ($avant['liens_parente'] as $l) {
            if ((int) $l['enfant_id'] !== $absorbee) {
                continue;
            }
            $existant = $actifs[$l['role_parent']] ?? null;
            if ($l['statut'] === 'rejete' || $existant === null) {
                Database::run('UPDATE liens_parente SET enfant_id = ? WHERE id = ?', [$conservee, $l['id']]);
                continue;
            }
            if ((int) $existant['parent_id'] !== (int) $l['parent_id']) {
                throw new ApiException(409, 'parents_differents', 'Ces deux fiches ont des parents différents : rejetez d\'abord le lien erroné.');
            }
            // Même parent déclaré deux fois : le lien le plus avancé survit, l'autre est rejeté.
            $perdant = self::RANG_STATUT[$l['statut']] > self::RANG_STATUT[$existant['statut']] ? $existant : $l;
            Database::run("UPDATE liens_parente SET statut = 'rejete', motif_rejet = ? WHERE id = ?", [$motif, $perdant['id']]);
            Database::run('UPDATE liens_parente SET enfant_id = ? WHERE id = ?', [$conservee, $l['id']]);
        }
        foreach ($avant['liens_parente'] as $l) {
            if ((int) $l['parent_id'] === $absorbee && (int) $l['enfant_id'] !== $conservee) {
                Database::run('UPDATE liens_parente SET parent_id = ? WHERE id = ?', [$conservee, $l['id']]);
            }
        }
    }

    private static function transfererUnions(array $avant, int $conservee, int $absorbee): void
    {
        $paires = [];
        $remplacer = static fn(int $id): int => $id === $absorbee ? $conservee : $id;
        foreach ($avant['unions'] as $u) {
            if ((int) $u['epoux_id'] !== $absorbee && (int) $u['epouse_id'] !== $absorbee && $u['statut'] !== 'rejete') {
                $paires[$u['epoux_id'] . '-' . $u['epouse_id']] = true;
            }
        }
        foreach ($avant['unions'] as $u) {
            if ((int) $u['epoux_id'] !== $absorbee && (int) $u['epouse_id'] !== $absorbee) {
                continue;
            }
            $epoux = $remplacer((int) $u['epoux_id']);
            $epouse = $remplacer((int) $u['epouse_id']);
            $cle = "$epoux-$epouse";
            $doublon = isset($paires[$cle]) && $u['statut'] !== 'rejete';
            Database::update('unions', ['epoux_id' => $epoux, 'epouse_id' => $epouse] + ($doublon ? ['statut' => 'rejete'] : []), 'id = ?', [$u['id']]);
            $paires[$cle] = true;
        }
    }

    /** Restitue chaque ligne touchée telle qu'elle était, identifiants et horodatages compris. */
    public static function annuler(array $u, int $fusionId): void
    {
        Database::tx(static function () use ($u, $fusionId): void {
            $f = Database::one('SELECT * FROM fusions WHERE id = ? FOR UPDATE', [$fusionId]);
            if ($f === null) {
                throw new ApiException(404, 'introuvable', 'Fusion introuvable.');
            }
            if ($f['annulee_le'] !== null) {
                throw new ApiException(409, 'deja_annulee', 'Cette fusion a déjà été annulée.');
            }
            $conservee = (int) $f['personne_conservee_id'];
            $absorbee = (int) $f['personne_absorbee_id'];
            $suivante = Database::value(
                'SELECT id FROM fusions WHERE id > ? AND annulee_le IS NULL AND (personne_conservee_id IN (?, ?) OR personne_absorbee_id IN (?, ?)) LIMIT 1',
                [$fusionId, $conservee, $absorbee, $conservee, $absorbee]
            );
            if ($suivante !== null) {
                throw new ApiException(409, 'fusion_posterieure', "Annulez d'abord la fusion n° $suivante, plus récente.");
            }
            $s = json_decode($f['snapshot_avant'], true);
            try {
                // Le lien survivant d'abord mis de côté évite une collision transitoire sur l'unicité.
                foreach ($s['liens_parente'] as $l) {
                    Database::run("UPDATE liens_parente SET statut = 'rejete' WHERE id = ?", [$l['id']]);
                }
                foreach (['liens_parente', 'unions', 'declarations_tiers', 'consentements', 'personnes'] as $table) {
                    foreach ($s[$table] as $ligne) {
                        self::restaurer($table, $ligne);
                    }
                }
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ApiException(409, 'annulation_impossible', 'Un lien créé depuis la fusion empêche l\'annulation : rejetez-le d\'abord.');
                }
                throw $e;
            }
            foreach ($s['noms_personne'] as $n) {
                $existe = Database::value('SELECT id FROM noms_personne WHERE id = ?', [$n['id']]) !== null;
                $existe ? self::restaurer('noms_personne', $n) : Database::insert('noms_personne', $n);
                NormalisationService::ecrireTokens((int) $n['id'], $n['valeur']);
            }
            foreach (['utilisateurs', 'branches', 'doublons_suspects'] as $table) {
                foreach ($s[$table] as $ligne) {
                    self::restaurer($table, $ligne);
                }
            }
            Database::update('fusions', ['annulee_par' => $u['id'], 'annulee_le' => gmdate('Y-m-d H:i:s')], 'id = ?', [$fusionId]);
            JournalService::ecrire((int) $u['id'], 'fusion.annulee', 'personne', $conservee, null, ['absorbee' => $absorbee, 'fusion' => $fusionId]);
        });
    }

    private static function restaurer(string $table, array $ligne): void
    {
        $id = $ligne['id'];
        unset($ligne['id']);
        Database::update($table, $ligne, 'id = ?', [$id]);
    }
}
