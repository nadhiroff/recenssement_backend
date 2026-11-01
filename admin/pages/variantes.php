<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\AdminService;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $faire = $_POST['faire'] ?? '';
    if ($faire === 'recalculer') {
        action(static fn() => AdminService::recalculerNoms($u), 'Noms normalisés recalculés.', 'variantes');
    }
    if ($faire === 'supprimer') {
        action(static fn() => AdminService::supprimerVariante($u, (int) $_POST['id']), 'Variante supprimée.', 'variantes');
    }
    action(static fn() => AdminService::enregistrerVariante($u, $_POST), 'Variante ajoutée. Pensez à recalculer.', 'variantes');
}

$liste = Database::all('SELECT * FROM variantes_noms ORDER BY forme_canonique, variante');
$outils = '<form method="post" action="' . e(url('variantes')) . '">' . champCsrf() . '<input type="hidden" name="faire" value="recalculer"><button class="contour">Recalculer les noms</button></form>';
haut('Variantes de noms', $u, 'variantes', 'Orthographes ramenées à une même forme pour le rapprochement', $outils);
?>
<div class="deux large remplir">
<div class="table"><table><thead><tr><th scope="col">Variante</th><th scope="col">Forme retenue</th><th scope="col"><span class="muet">Actions</span></th></tr></thead><tbody>
<?php foreach ($liste as $v) { ?>
<tr><td><?= e($v['variante']) ?></td><td><strong><?= e($v['forme_canonique']) ?></strong></td><td class="action"><form method="post" action="<?= e(url('variantes')) ?>" data-confirmer="Supprimer cette variante ?" data-oui="Oui, supprimer"><?= champCsrf() ?><input type="hidden" name="faire" value="supprimer"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="contour petit">Supprimer</button></form></td></tr>
<?php } if ($liste === []) { echo '<tr><td colspan="3" class="vide">Aucune variante.</td></tr>'; } ?>
</tbody></table></div>
<section class="carte"><h2>Ajouter une variante</h2>
<form method="post" action="<?= e(url('variantes')) ?>"><?= champCsrf() ?>
<label for="v">Variante</label><input id="v" name="variante" required placeholder="ZOKLI">
<label for="c">Forme retenue</label><input id="c" name="forme_canonique" required placeholder="ZOCLI">
<div class="boutons"><button>Ajouter</button></div></form></section>
</div>
<?php bas();
