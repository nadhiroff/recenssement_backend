<?php
declare(strict_types=1);

use App\Services\BackupService;
use App\Services\JournalService;
use App\Services\TacheService;

exigerAdminPage($u);

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
haut('Tâches et sauvegardes', $u, 'taches');
?>
<h1>Tâches et sauvegardes</h1>
<div class="carte table"><table><tr><th scope="col">Tâche</th><th scope="col">Dernière exécution (UTC)</th><th scope="col">Résultat</th><th scope="col"></th></tr>
<?php foreach (TacheService::etats() as $t) { ?>
<tr><td><?= e($libelles[$t['nom']]) ?></td><td><?= e($t['derniere_execution'] ?? 'jamais') ?></td><td class="muet"><?= e($t['statut']) ?> <?= e($t['resume']) ?></td>
<td><form method="post" action="<?= e(url('taches')) ?>"><?= champCsrf() ?><input type="hidden" name="nom" value="<?= e($t['nom']) ?>"><button class="contour">Lancer maintenant</button></form></td></tr>
<?php } ?>
</table></div>
<h2>Sauvegardes</h2>
<div class="carte table"><table><tr><th scope="col">Fichier</th><th scope="col">Taille</th><th scope="col"></th></tr>
<?php foreach (BackupService::liste() as $b) { ?>
<tr><td><?= e($b['fichier']) ?></td><td><?= number_format($b['taille'] / 1024, 0, ',', ' ') ?> Ko</td>
<td><a href="<?= e(url('taches', ['telecharger' => $b['fichier']])) ?>">Télécharger</a>
<form class="ligne" method="post" action="<?= e(url('taches')) ?>" data-confirmer="Restaurer cette sauvegarde ?" data-confirmer2="Toutes les données actuelles seront remplacées. Confirmer une dernière fois ?"><?= champCsrf() ?><input type="hidden" name="faire" value="restaurer"><input type="hidden" name="fichier" value="<?= e($b['fichier']) ?>"><input name="confirmation" placeholder="Tapez RESTAURER" required aria-label="Confirmation"><button class="danger">Restaurer</button></form></td></tr>
<?php } ?>
</table></div>
<?php bas();
