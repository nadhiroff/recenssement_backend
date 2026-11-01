<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\AdminService;

const PAR_PAGE = 8;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['faire'] ?? '') === 'compte') {
        action(static fn() => AdminService::modifierCompte($u, $id, $_POST), 'Accès mis à jour : la personne doit se reconnecter.', 'utilisateurs');
    }
    if (($_POST['faire'] ?? '') === 'reactiver') {
        action(static fn() => AdminService::reactiverCompte($u, $id), 'Compte rétabli.', 'utilisateurs');
    }
    action(
        static fn() => AdminService::definirRole($u, $id, (string) $_POST['role'], (array) ($_POST['branches'] ?? []), (array) ($_POST['branches'] ?? [])),
        'Rôle mis à jour.',
        'utilisateurs'
    );
}

$q = trim((string) ($_GET['q'] ?? ''));
$role = (string) ($_GET['role'] ?? '');
$conds = ['1 = 1'];
$params = [];
if (in_array($role, ['membre', 'relais', 'referent', 'admin'], true)) {
    $conds[] = 'role_global = ?';
    $params[] = $role;
} else {
    $role = '';
}
if ($q !== '') {
    $ids = PersonneRepository::rechercher($q, null, 200, 0);
    $conds[] = '(telephone_whatsapp LIKE ? OR email LIKE ?' . ($ids === [] ? '' : ' OR personne_id IN (' . implode(',', $ids) . ')') . ')';
    $motif = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $motif, $motif);
}
$ou = implode(' AND ', $conds);
$total = (int) Database::value("SELECT COUNT(*) FROM utilisateurs WHERE $ou", $params);
$page = max(1, (int) ($_GET['n'] ?? 1));
$offset = ($page - 1) * PAR_PAGE;
$liste = Database::all("SELECT id, personne_id, telephone_whatsapp, email, role_global, actif, derniere_connexion FROM utilisateurs WHERE $ou ORDER BY FIELD(role_global,'admin','referent','relais','membre'), id LIMIT " . PAR_PAGE . " OFFSET $offset", $params);
$fiches = PersonneRepository::parIds(array_filter(array_column($liste, 'personne_id')));
$branches = Database::all('SELECT id, nom FROM branches WHERE actif = 1 ORDER BY ordre');
$noms = array_column($branches, 'nom', 'id');
$rattachees = [];
foreach (Database::all('SELECT utilisateur_id, branche_id FROM referents_branche UNION SELECT utilisateur_id, branche_id FROM relais_branche') as $l) {
    $rattachees[(int) $l['utilisateur_id']][] = (int) $l['branche_id'];
}
$manque = AdminService::branchesSansDeuxReferents();
$filtres = '<form class="filtres" method="get"><input type="hidden" name="p" value="utilisateurs"><input type="search" name="q" value="' . e($q) . '" placeholder="Nom, téléphone ou email" aria-label="Rechercher"><select name="role" aria-label="Rôle"><option value="">Tous les rôles</option>';
foreach (['referent' => 'Référents', 'relais' => 'Relais', 'membre' => 'Membres'] as $v => $t) {
    $filtres .= '<option value="' . $v . '"' . ($role === $v ? ' selected' : '') . '>' . $t . '</option>';
}
$filtres .= '</select><button class="contour">Filtrer</button></form>';
haut('Utilisateurs et rôles', $u, 'utilisateurs', $total . ' compte(s)', $filtres);
if ($manque !== []) {
    echo '<div class="alerte attention">Il faut deux référents par branche : ' . e(implode(', ', array_map(static fn(array $b): string => $b['nom'] . ' en a ' . $b['nb'], $manque))) . '.</div>';
}
?>
<div class="table"><table>
<thead><tr><th scope="col">Personne</th><th scope="col">Téléphone</th><th scope="col">Rôle</th><th scope="col">Branches</th><th scope="col">Dernière connexion</th><th scope="col"><span class="muet">Actions</span></th></tr></thead><tbody>
<?php foreach ($liste as $l) { $id = (int) $l['id']; $mes = $rattachees[$id] ?? []; ?>
<tr><td><strong><?= e($fiches[$l['personne_id'] ?? 0]['nom_complet'] ?? 'Sans fiche') ?></strong><br><span class="muet"><?= e($l['email']) ?></span></td>
<td><?= e($l['telephone_whatsapp'] ?? '—') ?></td>
<td><?= badgeRole($l['role_global']) ?><?= $l['actif'] ? '' : ' <span class="badge inactif">Retiré</span>' ?></td>
<td class="muet"><?= e(implode(', ', array_map(static fn(int $b): string => $noms[$b] ?? '', $mes)) ?: '—') ?></td>
<td class="muet"><?= e($l['derniere_connexion'] ? substr($l['derniere_connexion'], 0, 10) : 'jamais') ?></td>
<td class="action"><button type="button" class="contour petit" data-ouvrir="gerer-<?= $id ?>">Gérer</button></td></tr>
<?php } if ($liste === []) { echo '<tr><td colspan="6" class="vide">Aucun compte ne correspond.</td></tr>'; } ?>
</tbody></table></div>
<?= pagination('utilisateurs', $page, PAR_PAGE, $total, array_filter(['q' => $q, 'role' => $role])) ?>
<?php foreach ($liste as $l) { $id = (int) $l['id']; $mes = $rattachees[$id] ?? []; ?>
<dialog id="gerer-<?= $id ?>"><div class="fenetre">
  <h2><?= e($fiches[$l['personne_id'] ?? 0]['nom_complet'] ?? ($l['email'] ?: 'Compte')) ?></h2>
  <section><h3>Rôle dans la famille</h3>
  <form method="post" action="<?= e(url('utilisateurs')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= $id ?>">
    <label for="role-<?= $id ?>">Rôle</label>
    <select id="role-<?= $id ?>" name="role"><?php foreach (['membre' => 'Membre', 'relais' => 'Relais', 'referent' => 'Référent'] + ($l['role_global'] === 'admin' ? ['admin' => 'Administrateur'] : []) as $v => $t) { echo '<option value="' . $v . '"' . ($l['role_global'] === $v ? ' selected' : '') . '>' . $t . '</option>'; } ?></select>
    <label>Branches suivies (relais ou référent)</label>
    <?php foreach ($branches as $b) { ?><label class="cocher"><input type="checkbox" name="branches[]" value="<?= (int) $b['id'] ?>" <?= in_array((int) $b['id'], $mes, true) ? 'checked' : '' ?>> <?= e($b['nom']) ?></label><?php } ?>
    <div class="boutons"><button>Enregistrer le rôle</button></div>
  </form></section>
  <section><h3>Accès perdu : vérifiez d'abord l'identité de la personne</h3>
  <form method="post" action="<?= e(url('utilisateurs')) ?>" data-confirmer="Modifier les accès de cette personne ? Elle devra se reconnecter." data-oui="Oui, modifier"><?= champCsrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="faire" value="compte">
    <div class="grille-champs"><div><label for="tel-<?= $id ?>">Téléphone</label><input id="tel-<?= $id ?>" name="telephone" value="<?= e($l['telephone_whatsapp']) ?>"></div>
    <div><label for="mail-<?= $id ?>">Email</label><input id="mail-<?= $id ?>" name="email" type="email" value="<?= e($l['email']) ?>"></div></div>
    <div class="boutons"><button class="contour">Changer les accès</button></div>
  </form>
  <?php if (!$l['actif']) { ?><form method="post" action="<?= e(url('utilisateurs')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="faire" value="reactiver"><div class="boutons"><button>Rétablir le compte</button></div></form><?php } ?>
  </section>
  <div class="boutons"><button type="button" class="contour" data-fermer>Fermer</button></div>
</div></dialog>
<?php }
bas();
