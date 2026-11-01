<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\AdminService;

exigerAdminPage($u);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $faire = $_POST['faire'] ?? '';
    if ($faire === 'fondateur') {
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
$branches = Database::all('SELECT * FROM branches ORDER BY ordre, nom');
$edition = null;
foreach ($branches as $b) {
    if ((int) $b['id'] === (int) ($_GET['id'] ?? 0)) {
        $edition = $b;
    }
}
haut('Fondateur et branches', $u, 'branches');
?>
<h1>Fondateur et branches</h1>
<div class="deux">
<div class="carte"><h2>Ancêtre fondateur</h2>
<form method="post" action="<?= e(url('branches')) ?>"><?= champCsrf() ?><input type="hidden" name="faire" value="fondateur">
<label for="nf">Nom de famille</label><input id="nf" name="nom_famille" required value="<?= e($nom($f, 'nom_famille') ?: 'ZOCLI') ?>">
<label for="pf">Prénoms</label><input id="pf" name="prenoms" required value="<?= e($nom($f, 'naissance')) ?>">
<label for="af">Année de naissance (approximative)</label><input id="af" name="annee_naissance" inputmode="numeric" value="<?= e($f['annee_naissance'] ?? '') ?>">
<label for="sf">Précision</label><select id="sf" name="precision_naissance">
<?php foreach (['environ' => 'Environ', 'decennie' => 'Décennie', 'exacte' => 'Exacte'] as $v => $l) { echo '<option value="' . $v . '"' . (($f['precision_naissance'] ?? '') === $v ? ' selected' : '') . '>' . $l . '</option>'; } ?>
</select>
<p><button>Enregistrer le fondateur</button></p></form></div>
<div class="carte"><h2><?= $edition ? 'Modifier la branche' : 'Nouvelle branche' ?></h2>
<form method="post" action="<?= e(url('branches')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= e($edition['id'] ?? '') ?>">
<label for="nb">Nom</label><input id="nb" name="nom" required maxlength="120" value="<?= e($edition['nom'] ?? '') ?>">
<label for="db">Description</label><textarea id="db" name="description" maxlength="500"><?= e($edition['description'] ?? '') ?></textarea>
<label for="ab">Identifiant de la fiche de l'ancêtre de branche</label><input id="ab" name="ancetre_personne_id" inputmode="numeric" value="<?= e($edition['ancetre_personne_id'] ?? '') ?>">
<label for="ob">Ordre</label><input id="ob" name="ordre" inputmode="numeric" value="<?= e($edition['ordre'] ?? 0) ?>">
<label><input type="checkbox" name="actif" <?= ($edition['actif'] ?? 1) ? 'checked' : '' ?>> Active</label>
<p><button>Enregistrer</button></p></form></div>
</div>
<div class="carte table"><table><tr><th scope="col">Branche</th><th scope="col">Description</th><th scope="col">Active</th><th scope="col"></th></tr>
<?php foreach ($branches as $b) { ?>
<tr><td><?= e($b['nom']) ?></td><td><?= e($b['description']) ?></td><td><?= $b['actif'] ? 'oui' : 'non' ?></td><td><a href="<?= e(url('branches', ['id' => $b['id']])) ?>">Modifier</a></td></tr>
<?php } ?>
</table></div>
<?php bas();
