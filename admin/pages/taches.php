<?php
declare(strict_types=1);

use App\Services\BackupService;
use App\Services\JournalService;
use App\Services\TacheService;

if (isset($_GET['telecharger'])) {
    $chemin = BackupService::chemin((string) $_GET['telecharger']);
    JournalService::ecrire((int) $u['id'], 'sauvegarde.telechargement', null, null, null, ['fichier' => basename($chemin)]);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($chemin) . '"');
    header('Content-Length: ' . filesize($chemin));
    readfile($chemin);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['faire'] ?? '') === 'restaurer') {
        if (($_POST['confirmation'] ?? '') !== 'RESTAURER') {
            flash('Tapez RESTAURER pour confirmer.', 'erreur');
            rediriger('taches');
        }
        $fichier = (string) $_POST['fichier'];
        action(static function () use ($u, $fichier): void {
            BackupService::restaurer($fichier);
            JournalService::ecrire((int) $u['id'], 'sauvegarde.restauration', null, null, null, ['fichier' => $fichier]);
        }, 'Base restaurée.', 'taches');
    }
    $nom = (string) ($_POST['nom'] ?? '');
    action(static function () use ($u, $nom): void {
        TacheService::lancer($nom);
        JournalService::ecrire((int) $u['id'], 'tache.lancement_manuel', 'tache', null, null, ['nom' => $nom]);
    }, 'Tâche terminée.', 'taches');
}

$libelles = ['detect_doublons' => 'Détection des doublons', 'relances' => 'Relances', 'backup' => 'Sauvegarde'];
$sauvegardes = BackupService::liste();
haut('Tâches et sauvegardes', $u, 'taches', 'Le travail automatique du système');
?>
<div class="tuiles">
<?php foreach (TacheService::etats() as $t) { ?>
<div class="tuile"><strong><?= e($libelles[$t['nom']]) ?></strong><br><span>Dernière fois : <?= e($t['derniere_execution'] ? substr($t['derniere_execution'], 0, 16) : 'jamais') ?></span>
<?= $t['statut'] === 'erreur' ? '<br><span class="badge rejete">Erreur</span>' : '' ?>
<form method="post" action="<?= e(url('taches')) ?>"><?= champCsrf() ?><input type="hidden" name="nom" value="<?= e($t['nom']) ?>"><div class="boutons"><button class="contour petit">Lancer maintenant</button></div></form></div>
<?php } ?>
</div>
<div class="table"><table><thead><tr><th scope="col">Sauvegarde</th><th scope="col">Taille</th><th scope="col"><span class="muet">Actions</span></th></tr></thead><tbody>
<?php foreach (array_slice($sauvegardes, 0, 11) as $i => $b) { ?>
<tr><td><?= e($b['date']) ?> <span class="muet"><?= str_contains($b['fichier'], 'hebdo') ? 'hebdomadaire' : 'quotidienne' ?></span></td><td class="muet"><?= number_format($b['taille'] / 1024, 0, ',', ' ') ?> Ko</td>
<td class="action"><a class="bouton contour petit" href="<?= e(url('taches', ['telecharger' => $b['fichier']])) ?>">Télécharger</a> <button type="button" class="contour petit" data-ouvrir="r-<?= $i ?>">Restaurer</button></td></tr>
<?php } if ($sauvegardes === []) { echo '<tr><td colspan="3" class="vide">Aucune sauvegarde.</td></tr>'; } ?>
</tbody></table></div>
<?php foreach (array_slice($sauvegardes, 0, 11) as $i => $b) { ?>
<dialog id="r-<?= $i ?>"><div class="fenetre"><h2>Restaurer cette sauvegarde</h2><p class="muet">Toutes les données actuelles seront remplacées par celles du <?= e($b['date']) ?>.</p>
<form method="post" action="<?= e(url('taches')) ?>" data-confirmer="Dernière vérification : remplacer toutes les données actuelles ?" data-oui="Oui, restaurer"><?= champCsrf() ?><input type="hidden" name="faire" value="restaurer"><input type="hidden" name="fichier" value="<?= e($b['fichier']) ?>">
<label for="c-<?= $i ?>">Tapez RESTAURER</label><input id="c-<?= $i ?>" name="confirmation" required autocomplete="off">
<div class="boutons"><button type="button" class="contour" data-fermer>Annuler</button><button class="danger">Restaurer</button></div></form></div></dialog>
<?php }
bas();
