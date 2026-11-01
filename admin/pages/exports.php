<?php
declare(strict_types=1);

use App\Core\Response;
use App\Services\CsvExporter;
use App\Services\GedcomExporter;
use App\Services\JournalService;

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
haut('Exports', $u, 'exports', 'Récupérer les données du recensement');
?>
<div class="deux">
<section class="carte"><h2>Arbre validé</h2><p class="muet">Format GEDCOM 5.5.1, lisible par Gramps. Uniquement les liens validés et les fiches publiques.</p><div class="boutons"><a class="bouton" href="<?= e(url('exports', ['telecharger' => 'gedcom'])) ?>">Télécharger l'arbre</a></div></section>
<section class="carte"><h2>Toutes les fiches</h2><p class="muet">Format Excel, téléphones compris : à conserver en lieu sûr.</p><div class="boutons"><a class="bouton" href="<?= e(url('exports', ['telecharger' => 'csv'])) ?>">Télécharger les fiches</a></div></section>
</div>
<?php bas();
