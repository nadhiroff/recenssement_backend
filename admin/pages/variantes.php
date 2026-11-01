<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\AdminService;

exigerAdminPage($u);

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
haut('Variantes de noms', $u, 'variantes');
?>
<h1>Variantes de noms</h1>
<div class="carte"><form method="post" action="<?= e(url('variantes')) ?>"><?= champCsrf() ?>
<div class="deux"><div><label for="v">Variante</label><input id="v" name="variante" required placeholder="ZOKLI"></div>
<div><label for="c">Forme canonique</label><input id="c" name="forme_canonique" required placeholder="ZOCLI"></div></div>
<p><button>Ajouter</button></p></form>
<form method="post" action="<?= e(url('variantes')) ?>"><?= champCsrf() ?><input type="hidden" name="faire" value="recalculer"><button class="contour">Recalculer les noms normalisés</button></form></div>
<div class="carte table"><table><tr><th scope="col">Variante</th><th scope="col">Forme canonique</th><th scope="col"></th></tr>
<?php foreach ($liste as $v) { ?>
<tr><td><?= e($v['variante']) ?></td><td><?= e($v['forme_canonique']) ?></td><td><form method="post" action="<?= e(url('variantes')) ?>" data-confirmer="Supprimer cette variante ?"><?= champCsrf() ?><input type="hidden" name="faire" value="supprimer"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="contour">Supprimer</button></form></td></tr>
<?php } ?>
</table></div>
<?php bas();
