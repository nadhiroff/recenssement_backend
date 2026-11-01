<?php
declare(strict_types=1);

use App\Core\Response;
use App\Services\CsvExporter;
use App\Services\GedcomExporter;
use App\Services\JournalService;
use App\Services\XlsxExporter;

$quoi = (string) ($_GET['telecharger'] ?? '');
$excel = static function (string $feuille, array $tableau, string $fichier) use ($u): never {
    JournalService::ecrire((int) $u['id'], 'export.excel', null, null, null, ['fichier' => $fichier]);
    Response::telechargement(XlsxExporter::tableau($feuille, $tableau[0], $tableau[1]), $fichier, XlsxExporter::type());
    exit;
};
if ($quoi === 'fiches') {
    $excel('Fiches', CsvExporter::donneesFiches(), 'fiches_zocli.xlsx');
}
if ($quoi === 'arbre') {
    $excel('Arbre validé', CsvExporter::donneesArbre(), 'arbre_valide_zocli.xlsx');
}
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
<section class="carte"><h2>Arbre validé</h2><p class="muet">À lire dans Excel : chaque personne avec son père, sa mère, ses conjoints et ses enfants. Uniquement les liens validés, sans téléphone.</p><div class="boutons"><a class="bouton" href="<?= e(url('exports', ['telecharger' => 'arbre'])) ?>">Télécharger pour Excel</a></div></section>
<section class="carte"><h2>Toutes les fiches</h2><p class="muet">À lire dans Excel, téléphones compris : à conserver en lieu sûr.</p><div class="boutons"><a class="bouton contour" href="<?= e(url('exports', ['telecharger' => 'csv'])) ?>">Format CSV</a><a class="bouton" href="<?= e(url('exports', ['telecharger' => 'fiches'])) ?>">Télécharger pour Excel</a></div></section>
<section class="carte"><h2>Fichier pour logiciel de généalogie</h2><p class="muet">Format GEDCOM, pour ouvrir l'arbre dans Gramps et l'imprimer. Ce fichier n'est pas fait pour être lu : ouvert dans un éditeur de texte, il ressemble à du code, c'est normal.</p><div class="boutons"><a class="bouton contour" href="<?= e(url('exports', ['telecharger' => 'gedcom'])) ?>">Télécharger pour Gramps</a></div></section>
</div>
<?php bas();
