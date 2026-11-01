<?php
declare(strict_types=1);

use App\Core\ApiException;
use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\LienService;
use App\Services\PermissionService;

PermissionService::exigerReferent($u);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $motif = ['motif' => $_POST['motif'] ?? ''];
    action(static function () use ($u, $id, $motif): void {
        match ($_POST['faire'] ?? '') {
            'valider' => LienService::valider($u, $id),
            'examen' => LienService::examiner($u, $id),
            'prive' => LienService::rendrePrive($u, $id, $motif),
            'rejeter' => LienService::rejeter($u, $id, $motif),
            default => throw new ApiException(400, 'action', 'Action inconnue.'),
        };
    }, 'Lien mis à jour.', 'lien', ['id' => $id]);
}

$statut = (string) ($_GET['statut'] ?? '');
$branche = (int) ($_GET['branche'] ?? 0);
$conds = [];
$params = [];
if ($statut !== '' && isset(LienService::TRANSITIONS[$statut])) {
    $conds[] = 'l.statut = ?';
    $params[] = $statut;
} else {
    $statut = '';
    $conds[] = "l.statut IN ('declare','confirme','en_examen')";
}
$mes = PermissionService::estAdmin($u) ? null : PermissionService::branchesReferent($u);
if ($mes !== null) {
    $conds[] = $mes === [] ? '1 = 0' : 'e.branche_id IN (' . implode(',', $mes) . ')';
}
if ($branche > 0) {
    $conds[] = 'e.branche_id = ?';
    $params[] = $branche;
}
$liens = Database::all(
    'SELECT l.*, e.branche_id FROM liens_parente l JOIN personnes e ON e.id = l.enfant_id WHERE ' . implode(' AND ', $conds)
    . " ORDER BY FIELD(l.statut,'confirme','en_examen','declare'), l.declare_le LIMIT 100",
    $params
);
$fiches = PersonneRepository::parIds(array_merge(array_column($liens, 'parent_id'), array_column($liens, 'enfant_id')));
$branches = Database::all('SELECT id, nom FROM branches WHERE actif = 1' . ($mes === null || $mes === [] ? '' : ' AND id IN (' . implode(',', $mes) . ')') . ' ORDER BY ordre');
haut('File de validation', $u, 'file');
?>
<h1>File de validation</h1>
<form class="ligne" method="get"><input type="hidden" name="p" value="file">
<select name="statut" aria-label="Statut"><option value="">À traiter</option><?php foreach (['declare' => 'Déclaré', 'confirme' => 'Confirmé', 'en_examen' => 'En examen', 'prive' => 'Privé', 'valide' => 'Validé', 'rejete' => 'Rejeté'] as $v => $t) { echo '<option value="' . $v . '"' . ($statut === $v ? ' selected' : '') . '>' . $t . '</option>'; } ?></select>
<select name="branche" aria-label="Branche"><option value="0">Toutes les branches</option><?php foreach ($branches as $b) { echo '<option value="' . (int) $b['id'] . '"' . ($branche === (int) $b['id'] ? ' selected' : '') . '>' . e($b['nom']) . '</option>'; } ?></select>
<button class="contour">Filtrer</button></form>
<div class="carte table"><table><tr><th scope="col">Parent</th><th scope="col">Enfant</th><th scope="col">Rôle</th><th scope="col">Statut</th><th scope="col">Déclaré le</th><th scope="col"></th></tr>
<?php foreach ($liens as $l) { $conflit = PermissionService::conflitInteret($u, $l); ?>
<tr><td><?= e($fiches[$l['parent_id']]['nom_complet'] ?? '') ?></td><td><?= e($fiches[$l['enfant_id']]['nom_complet'] ?? '') ?></td>
<td><?= $l['role_parent'] === 'pere' ? 'Père' : 'Mère' ?></td><td><?= badge($l['statut']) ?><?= $conflit ? '<br><span class="muet">À traiter par un autre référent</span>' : '' ?></td>
<td><?= e(substr($l['declare_le'], 0, 10)) ?></td><td><a href="<?= e(url('lien', ['id' => $l['id']])) ?>">Ouvrir</a></td></tr>
<?php } if ($liens === []) { echo '<tr><td colspan="6" class="muet">Rien à traiter.</td></tr>'; } ?>
</table></div>
<?php bas();
