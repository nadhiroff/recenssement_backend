<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Response;
use App\Services\CsvExporter;
use App\Services\JournalService;
use App\Services\SuiviService;

const PAR_PAGE = 12;

$filtres = array_filter(array_intersect_key($_GET, ['action' => 1, 'entite_type' => 1, 'utilisateur_id' => 1]), static fn($v): bool => $v !== '');

if (isset($_GET['csv'])) {
    JournalService::ecrire((int) $u['id'], 'export.journal');
    Response::telechargement(CsvExporter::journal(SuiviService::journal(null, $filtres, 50000, 0)), 'journal_zocli.csv', 'text/csv; charset=utf-8');
    exit;
}
$page = max(1, (int) ($_GET['n'] ?? 1));
$lignes = SuiviService::journal(null, $filtres, PAR_PAGE + 1, ($page - 1) * PAR_PAGE);
$suite = count($lignes) > PAR_PAGE;
$lignes = array_slice($lignes, 0, PAR_PAGE);
$actions = array_column(Database::all('SELECT DISTINCT action FROM journal_actions ORDER BY action'), 'action');
$choix = '<form class="filtres" method="get"><input type="hidden" name="p" value="journal"><select name="action" aria-label="Action"><option value="">Toutes les actions</option>';
foreach ($actions as $a) {
    $choix .= '<option value="' . e($a) . '"' . (($filtres['action'] ?? '') === $a ? ' selected' : '') . '>' . e(libelleAction($a)) . '</option>';
}
$choix .= '</select><button class="contour">Filtrer</button><a class="bouton contour" href="' . e(url('journal', $filtres + ['csv' => 1])) . '">Export CSV</a></form>';
haut('Journal', $u, 'journal', 'Toutes les actions, sans modification possible', $choix);
?>
<div class="table"><table><thead><tr><th scope="col">Date (UTC)</th><th scope="col">Qui</th><th scope="col">Action</th><th scope="col">Sur</th></tr></thead><tbody>
<?php foreach ($lignes as $j) { ?>
<tr><td class="muet"><?= e(substr($j['cree_le'], 0, 16)) ?></td><td><?= $j['utilisateur_id'] === null ? 'Système' : 'Compte n° ' . (int) $j['utilisateur_id'] ?></td><td><?= e(libelleAction($j['action'])) ?></td><td class="muet"><?= e($j['entite_type']) ?> <?= e($j['entite_id']) ?></td></tr>
<?php } if ($lignes === []) { echo '<tr><td colspan="4" class="vide">Aucune action.</td></tr>'; } ?>
</tbody></table></div>
<?php if ($page > 1 || $suite) { ?><div class="pages">
<?php if ($page > 1) { echo '<a class="bouton contour petit" href="' . e(url('journal', $filtres + ['n' => $page - 1])) . '">Plus récentes</a>'; } ?><span>Page <?= $page ?></span>
<?php if ($suite) { echo '<a class="bouton contour petit" href="' . e(url('journal', $filtres + ['n' => $page + 1])) . '">Plus anciennes</a>'; } ?></div><?php } ?>
<?php bas();
