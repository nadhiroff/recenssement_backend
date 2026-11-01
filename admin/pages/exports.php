<?php
declare(strict_types=1);

use App\Core\Response;
use App\Services\CsvExporter;
use App\Services\GedcomExporter;
use App\Services\JournalService;

exigerAdminPage($u);

$quoi = $_GET['telecharger'] ?? '';
if ($quoi === 'gedcom') {
    JournalService::ecrire((int) $u['id'], 'export.gedcom');
    Response::telechargement(GedcomExporter::generer(), 'recensement_zocli.ged', 'text/plain; charset=utf-8');
    exit;
}
if ($quoi === 'csv') {
    JournalService::ecrire((int) $u['id'], 'export.csv');
    Response::telechargement(CsvExporter::fiches(), 'fiches_zocli.csv', 'text/csv; charset=utf-8');
    exit;
}
haut('Exports', $u, 'exports');
?>
<h1>Exports</h1>
<div class="deux">
<div class="carte"><h2>Arbre validé (GEDCOM 5.5.1)</h2><p class="muet">Uniquement les liens validés et les fiches publiques. Lisible par Gramps.</p><a class="bouton" href="<?= e(url('exports', ['telecharger' => 'gedcom'])) ?>">Télécharger</a></div>
<div class="carte"><h2>Fiches (CSV)</h2><p class="muet">Toutes les fiches, téléphones compris : à conserver en lieu sûr. Format Excel français.</p><a class="bouton" href="<?= e(url('exports', ['telecharger' => 'csv'])) ?>">Télécharger</a></div>
</div>
<?php bas();
