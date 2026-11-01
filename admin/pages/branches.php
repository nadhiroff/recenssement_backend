<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\AdminService;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['faire'] ?? '') === 'fondateur') {
        action(static fn() => AdminService::enregistrerFondateur($u, $_POST), 'Fondateur enregistré.', 'branches');
    }
    $id = ($_POST['id'] ?? '') === '' ? null : (int) $_POST['id'];
    $d = $_POST;
    $d['actif'] = isset($_POST['actif']);
    action(static fn() => AdminService::enregistrerBranche($u, $d, $id), 'Branche enregistrée.', 'branches');
}

$f = AdminService::fondateur();
$nom = static function (?array $p, string $type): string {
    foreach ($p['noms'] ?? [] as $n) {
        if ($n['type'] === $type) {
            return $n['valeur'];
        }
    }
    return '';
};
$branches = Database::all(
    "SELECT b.*, (SELECT COUNT(*) FROM personnes p WHERE p.branche_id = b.id AND p.fusionnee_dans_id IS NULL AND p.statut_fiche = 'active') AS fiches FROM branches b ORDER BY b.ordre, b.nom"
);
$ancetres = PersonneRepository::parIds(array_filter(array_column($branches, 'ancetre_personne_id')));
$fenetre = static function (string $idF, string $titre, ?array $b): void { ?>
<dialog id="<?= e($idF) ?>"><div class="fenetre"><h2><?= e($titre) ?></h2>
<form method="post" action="<?= e(url('branches')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= e($b['id'] ?? '') ?>">
<label for="<?= e($idF) ?>-n">Nom</label><input id="<?= e($idF) ?>-n" name="nom" required maxlength="120" value="<?= e($b['nom'] ?? '') ?>">
<label for="<?= e($idF) ?>-d">Description</label><textarea id="<?= e($idF) ?>-d" name="description" rows="2" maxlength="500"><?= e($b['description'] ?? '') ?></textarea>
<div class="grille-champs"><div><label for="<?= e($idF) ?>-a">N° de fiche de l'ancêtre</label><input id="<?= e($idF) ?>-a" name="ancetre_personne_id" inputmode="numeric" value="<?= e($b['ancetre_personne_id'] ?? '') ?>"></div>
<div><label for="<?= e($idF) ?>-o">Ordre</label><input id="<?= e($idF) ?>-o" name="ordre" inputmode="numeric" value="<?= e($b['ordre'] ?? 0) ?>"></div></div>
<label class="cocher"><input type="checkbox" name="actif" <?= ($b['actif'] ?? 1) ? 'checked' : '' ?>> Branche active</label>
<div class="boutons"><button type="button" class="contour" data-fermer>Annuler</button><button>Enregistrer</button></div></form></div></dialog>
<?php };
$an = $f['annee_naissance'] ?? null;
haut('Fondateur et branches', $u, 'branches', 'Les points d\'appui fixés par les doyens', '<button type="button" class="contour" data-ouvrir="fondateur">Modifier le fondateur</button><button type="button" data-ouvrir="branche-nouvelle">Nouvelle branche</button>');
?>
<div class="tuiles">
  <div class="tuile or"><b><?= e($f['nom_complet'] ?? 'À désigner') ?></b><span>ancêtre fondateur<?= $an ? ', vers ' . e($an) : '' ?></span></div>
  <div class="tuile"><b><?= count($branches) ?></b><span>branches</span></div>
</div>
<div class="table"><table><thead><tr><th scope="col">Branche</th><th scope="col">Description</th><th scope="col">Ancêtre</th><th scope="col">Fiches</th><th scope="col">État</th><th scope="col"><span class="muet">Actions</span></th></tr></thead><tbody>
<?php foreach ($branches as $b) { ?>
<tr><td><strong><?= e($b['nom']) ?></strong></td><td class="muet"><?= e($b['description']) ?></td><td><?= e($ancetres[$b['ancetre_personne_id'] ?? 0]['nom_complet'] ?? '—') ?></td><td><?= (int) $b['fiches'] ?></td><td><?= $b['actif'] ? '<span class="badge valide">Active</span>' : '<span class="badge inactif">Inactive</span>' ?></td>
<td class="action"><button type="button" class="contour petit" data-ouvrir="branche-<?= (int) $b['id'] ?>">Modifier</button></td></tr>
<?php } ?>
</tbody></table></div>
<?php
foreach ($branches as $b) {
    $fenetre('branche-' . $b['id'], 'Modifier la branche', $b);
}
$fenetre('branche-nouvelle', 'Nouvelle branche', null);
?>
<dialog id="fondateur"><div class="fenetre"><h2>Ancêtre fondateur</h2>
<form method="post" action="<?= e(url('branches')) ?>"><?= champCsrf() ?><input type="hidden" name="faire" value="fondateur">
<div class="grille-champs"><div><label for="nf">Nom de famille</label><input id="nf" name="nom_famille" required value="<?= e($nom($f, 'nom_famille') ?: 'ZOCLI') ?>"></div>
<div><label for="pf">Prénoms</label><input id="pf" name="prenoms" required value="<?= e($nom($f, 'naissance')) ?>"></div>
<div><label for="af">Année de naissance</label><input id="af" name="annee_naissance" inputmode="numeric" value="<?= e($an ?? '') ?>"></div>
<div><label for="sf">Précision</label><select id="sf" name="precision_naissance"><?php foreach (['environ' => 'Environ', 'decennie' => 'Décennie', 'exacte' => 'Exacte'] as $v => $l) { echo '<option value="' . $v . '"' . (($f['precision_naissance'] ?? '') === $v ? ' selected' : '') . '>' . $l . '</option>'; } ?></select></div></div>
<div class="boutons"><button type="button" class="contour" data-fermer>Annuler</button><button>Enregistrer</button></div></form></div></dialog>
<?php bas();
