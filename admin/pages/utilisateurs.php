<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\AdminService;

exigerAdminPage($u);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    action(
        static fn() => AdminService::definirRole($u, (int) $_POST['id'], (string) $_POST['role'], (array) ($_POST['branches'] ?? []), (array) ($_POST['branches'] ?? [])),
        'Rôle mis à jour.',
        'utilisateurs'
    );
}

$page = max(1, (int) ($_GET['n'] ?? 1));
$offset = ($page - 1) * 50;
$liste = Database::all("SELECT id, personne_id, telephone_whatsapp, email, role_global, actif FROM utilisateurs ORDER BY FIELD(role_global,'admin','referent','relais','membre'), id LIMIT 50 OFFSET $offset");
$fiches = PersonneRepository::parIds(array_filter(array_column($liste, 'personne_id')));
$branches = Database::all('SELECT id, nom FROM branches WHERE actif = 1 ORDER BY ordre');
$rattachees = [];
foreach (Database::all('SELECT utilisateur_id, branche_id FROM referents_branche UNION SELECT utilisateur_id, branche_id FROM relais_branche') as $l) {
    $rattachees[(int) $l['utilisateur_id']][] = (int) $l['branche_id'];
}
haut('Utilisateurs et rôles', $u, 'utilisateurs');
echo '<h1>Utilisateurs et rôles</h1>';
foreach (AdminService::branchesSansDeuxReferents() as $b) {
    echo '<div class="alerte attention">La ' . e($b['nom']) . ' compte ' . (int) $b['nb'] . ' référent(s) au lieu de 2.</div>';
}
?>
<div class="carte table"><table><tr><th scope="col">Personne</th><th scope="col">Téléphone</th><th scope="col">Rôle et branches</th></tr>
<?php foreach ($liste as $l) { ?>
<tr><td><?= e($fiches[$l['personne_id']]['nom_complet'] ?? '—') ?><br><span class="muet"><?= e($l['email']) ?></span></td><td><?= e($l['telephone_whatsapp']) ?></td><td>
<form class="ligne" method="post" action="<?= e(url('utilisateurs')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
<select name="role" aria-label="Rôle"><?php foreach (['membre' => 'Membre', 'relais' => 'Relais', 'referent' => 'Référent', 'admin' => 'Administrateur'] as $v => $t) { echo '<option value="' . $v . '"' . ($l['role_global'] === $v ? ' selected' : '') . '>' . $t . '</option>'; } ?></select>
<?php foreach ($branches as $b) { ?><label class="muet"><input type="checkbox" name="branches[]" value="<?= (int) $b['id'] ?>" <?= in_array((int) $b['id'], $rattachees[(int) $l['id']] ?? [], true) ? 'checked' : '' ?>> <?= e($b['nom']) ?></label><?php } ?>
<button class="contour">Enregistrer</button></form></td></tr>
<?php } ?>
</table></div>
<p><?php if ($page > 1) { echo '<a href="' . e(url('utilisateurs', ['n' => $page - 1])) . '">← Précédents</a> '; } if (count($liste) === 50) { echo '<a href="' . e(url('utilisateurs', ['n' => $page + 1])) . '">Suivants →</a>'; } ?></p>
<?php bas();
