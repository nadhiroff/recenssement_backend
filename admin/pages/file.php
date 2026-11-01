<?php
declare(strict_types=1);

use App\Core\ApiException;
use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\LienService;
use App\Services\PermissionService;

const PAR_PAGE = 8;

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
if ($branche > 0) {
    $conds[] = 'e.branche_id = ?';
    $params[] = $branche;
}
$ou = implode(' AND ', $conds);
$total = (int) Database::value("SELECT COUNT(*) FROM liens_parente l JOIN personnes e ON e.id = l.enfant_id WHERE $ou", $params);
$page = max(1, (int) ($_GET['n'] ?? 1));
$offset = ($page - 1) * PAR_PAGE;
$liens = Database::all(
    "SELECT l.*, e.branche_id FROM liens_parente l JOIN personnes e ON e.id = l.enfant_id WHERE $ou
     ORDER BY FIELD(l.statut,'confirme','en_examen','declare'), l.declare_le LIMIT " . PAR_PAGE . " OFFSET $offset",
    $params
);
$fiches = PersonneRepository::parIds(array_merge(array_column($liens, 'parent_id'), array_column($liens, 'enfant_id')));
$branches = Database::all('SELECT id, nom FROM branches WHERE actif = 1 ORDER BY ordre');
$noms = array_column($branches, 'nom', 'id');
$selection = '<form class="filtres" method="get"><input type="hidden" name="p" value="file"><input type="hidden" name="statut" value="' . e($statut) . '"><select name="branche" aria-label="Branche"><option value="0">Toutes les branches</option>';
foreach ($branches as $b) {
    $selection .= '<option value="' . (int) $b['id'] . '"' . ($branche === (int) $b['id'] ? ' selected' : '') . '>' . e($b['nom']) . '</option>';
}
$selection .= '</select><button class="contour">Filtrer</button></form>';
haut('Liens à valider', $u, 'file', $total . ' lien(s)', $selection);
?>
<div class="pastilles">
<?php foreach (['' => 'À traiter'] + LIBELLES_STATUT as $v => $t) { echo '<a class="pastille' . ($statut === $v ? ' actif' : '') . '" href="' . e(url('file', array_filter(['statut' => $v, 'branche' => $branche]))) . '">' . e($t) . '</a>'; } ?>
</div>
<div class="table"><table><thead><tr><th scope="col">Parent</th><th scope="col">Enfant</th><th scope="col">Rôle</th><th scope="col">Branche</th><th scope="col">Statut</th><th scope="col">Déclaré le</th><th scope="col"><span class="muet">Actions</span></th></tr></thead><tbody>
<?php foreach ($liens as $l) { ?>
<tr><td><?= e($fiches[$l['parent_id']]['nom_complet'] ?? '') ?></td><td><?= e($fiches[$l['enfant_id']]['nom_complet'] ?? '') ?></td><td><?= $l['role_parent'] === 'pere' ? 'Père' : 'Mère' ?></td><td class="muet"><?= e($noms[$l['branche_id'] ?? 0] ?? '—') ?></td>
<td><?= badge($l['statut']) ?><?= PermissionService::conflitInteret($u, $l) ? '<br><span class="muet">Par un autre référent</span>' : '' ?></td><td class="muet"><?= e(substr($l['declare_le'], 0, 10)) ?></td>
<td class="action"><a class="bouton contour petit" href="<?= e(url('lien', ['id' => $l['id']])) ?>">Ouvrir</a></td></tr>
<?php } if ($liens === []) { echo '<tr><td colspan="7" class="vide">Rien à traiter.</td></tr>'; } ?>
</tbody></table></div>
<?= pagination('file', $page, PAR_PAGE, $total, array_filter(['statut' => $statut, 'branche' => $branche])) ?>
<?php bas();
