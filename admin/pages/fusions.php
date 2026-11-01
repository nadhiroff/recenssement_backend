<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\FusionService;

const PAR_PAGE = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    action(static fn() => FusionService::annuler($u, (int) $_POST['id']), 'Fusion annulée : les liens d\'origine sont restitués.', 'fusions');
}

$total = (int) Database::value('SELECT COUNT(*) FROM fusions');
$page = max(1, (int) ($_GET['n'] ?? 1));
$offset = ($page - 1) * PAR_PAGE;
$liste = Database::all('SELECT id, personne_conservee_id, personne_absorbee_id, effectuee_le, annulee_le FROM fusions ORDER BY id DESC LIMIT ' . PAR_PAGE . " OFFSET $offset");
$fiches = PersonneRepository::parIds(array_merge(array_column($liste, 'personne_conservee_id'), array_column($liste, 'personne_absorbee_id')));
haut('Fusions', $u, 'fusions', 'Une fusion ne supprime rien et peut être annulée');
?>
<div class="table"><table><thead><tr><th scope="col">Date</th><th scope="col">Fiche conservée</th><th scope="col">Fiche absorbée</th><th scope="col">État</th><th scope="col"><span class="muet">Actions</span></th></tr></thead><tbody>
<?php foreach ($liste as $f) { ?>
<tr><td class="muet"><?= e(substr($f['effectuee_le'], 0, 16)) ?></td><td><?= e($fiches[$f['personne_conservee_id']]['nom_complet'] ?? '') ?> <span class="muet">n° <?= (int) $f['personne_conservee_id'] ?></span></td><td><?= e($fiches[$f['personne_absorbee_id']]['nom_complet'] ?? '') ?> <span class="muet">n° <?= (int) $f['personne_absorbee_id'] ?></span></td>
<td><?= $f['annulee_le'] ? '<span class="badge inactif">Annulée</span>' : '<span class="badge valide">Effective</span>' ?></td>
<td class="action"><?php if (!$f['annulee_le']) { ?><form method="post" action="<?= e(url('fusions')) ?>" data-confirmer="Annuler cette fusion et restituer les liens d'origine ?" data-oui="Oui, annuler la fusion"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="contour petit">Annuler</button></form><?php } ?></td></tr>
<?php } if ($liste === []) { echo '<tr><td colspan="5" class="vide">Aucune fusion pour le moment.</td></tr>'; } ?>
</tbody></table></div>
<?= pagination('fusions', $page, PAR_PAGE, $total) ?>
<?php bas();
