<?php
declare(strict_types=1);

use App\Core\Response;
use App\Services\CsvExporter;
use App\Services\JournalService;
use App\Services\PermissionService;
use App\Services\SuiviService;

$portee = PermissionService::estAdmin($u) ? null : PermissionService::branchesReferent($u);
$filtres = array_intersect_key($_GET, ['action' => 1, 'entite_type' => 1, 'utilisateur_id' => 1]);

if (isset($_GET['csv'])) {
    JournalService::ecrire((int) $u['id'], 'export.journal');
    Response::telechargement(CsvExporter::journal(SuiviService::journal($portee, $filtres, 50000, 0)), 'journal_zocli.csv', 'text/csv; charset=utf-8');
    exit;
}
$page = max(1, (int) ($_GET['n'] ?? 1));
$lignes = SuiviService::journal($portee, $filtres, 50, ($page - 1) * 50);
haut('Journal', $u, 'journal');
?>
<h1>Journal des actions</h1>
<form class="ligne" method="get"><input type="hidden" name="p" value="journal">
<input name="action" placeholder="Action (ex. lien.validation)" value="<?= e($filtres['action'] ?? '') ?>" aria-label="Action">
<input name="entite_type" placeholder="Type (lien, personne…)" value="<?= e($filtres['entite_type'] ?? '') ?>" aria-label="Type">
<input name="utilisateur_id" placeholder="N° utilisateur" value="<?= e($filtres['utilisateur_id'] ?? '') ?>" aria-label="Utilisateur">
<button class="contour">Filtrer</button>
<a class="bouton" href="<?= e(url('journal', $filtres + ['csv' => 1])) ?>">Export CSV</a></form>
<div class="carte table"><table><tr><th scope="col">Date (UTC)</th><th scope="col">Utilisateur</th><th scope="col">Action</th><th scope="col">Fiche</th><th scope="col">Détails</th></tr>
<?php foreach ($lignes as $j) { ?>
<tr><td><?= e($j['cree_le']) ?></td><td><?= e($j['utilisateur_id'] ?? 'système') ?></td><td><?= e($j['action']) ?></td><td><?= e($j['entite_type']) ?> <?= e($j['entite_id']) ?></td><td class="muet"><?= e($j['details']) ?></td></tr>
<?php } ?>
</table></div>
<p><?php if ($page > 1) { echo '<a href="' . e(url('journal', $filtres + ['n' => $page - 1])) . '">← Plus récents</a> '; } if (count($lignes) === 50) { echo '<a href="' . e(url('journal', $filtres + ['n' => $page + 1])) . '">Plus anciens →</a>'; } ?></p>
<?php bas();
