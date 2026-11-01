<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\FusionService;

exigerAdminPage($u);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    action(static fn() => FusionService::annuler($u, (int) $_POST['id']), 'Fusion annulée : les liens d\'origine sont restitués.', 'fusions');
}

$liste = Database::all('SELECT id, personne_conservee_id, personne_absorbee_id, effectuee_le, annulee_le FROM fusions ORDER BY id DESC LIMIT 100');
$fiches = PersonneRepository::parIds(array_merge(array_column($liste, 'personne_conservee_id'), array_column($liste, 'personne_absorbee_id')));
haut('Fusions', $u, 'fusions');
?>
<h1>Fusions</h1>
<div class="carte table"><table><tr><th scope="col">Date (UTC)</th><th scope="col">Conservée</th><th scope="col">Absorbée</th><th scope="col">État</th></tr>
<?php foreach ($liste as $f) { ?>
<tr><td><?= e($f['effectuee_le']) ?></td><td>n° <?= (int) $f['personne_conservee_id'] ?> <?= e($fiches[$f['personne_conservee_id']]['nom_complet'] ?? '') ?></td><td>n° <?= (int) $f['personne_absorbee_id'] ?> <?= e($fiches[$f['personne_absorbee_id']]['nom_complet'] ?? '') ?></td>
<td><?php if ($f['annulee_le']) { echo 'Annulée le ' . e($f['annulee_le']); } else { ?>
<form method="post" action="<?= e(url('fusions')) ?>" data-confirmer="Annuler cette fusion et restituer les liens d'origine ?"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="contour">Annuler</button></form><?php } ?></td></tr>
<?php } if ($liste === []) { echo '<tr><td colspan="4" class="muet">Aucune fusion.</td></tr>'; } ?>
</table></div>
<?php bas();
