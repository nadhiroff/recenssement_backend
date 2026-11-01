<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\AdminService;

exigerAdminPage($u);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valeurs = array_diff_key($_POST, ['csrf' => 1]);
    action(static fn() => AdminService::modifierParametres($u, $valeurs), 'Paramètres enregistrés.', 'parametres');
}

$liste = Database::all('SELECT * FROM parametres ORDER BY cle');
haut('Paramètres', $u, 'parametres');
?>
<h1>Paramètres</h1>
<div class="carte"><form method="post" action="<?= e(url('parametres')) ?>"><?= champCsrf() ?>
<div class="deux"><?php foreach ($liste as $p) { ?>
<div><label for="<?= e($p['cle']) ?>"><?= e($p['description'] ?: $p['cle']) ?></label><input id="<?= e($p['cle']) ?>" name="<?= e($p['cle']) ?>" inputmode="numeric" value="<?= e($p['valeur']) ?>" required></div>
<?php } ?></div>
<p><button>Enregistrer</button></p></form></div>
<?php bas();
