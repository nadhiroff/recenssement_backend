<?php
declare(strict_types=1);

use App\Services\AdminService;
use App\Services\PermissionService;
use App\Services\SuiviService;

$admin = PermissionService::estAdmin($u);
$portee = $admin ? null : PermissionService::branchesReferent($u);
$stats = SuiviService::stats($portee);
$attente = 0;
$doublons = 0;
foreach ($stats as $b) {
    $attente += $b['liens']['declare'] + $b['liens']['confirme'] + $b['liens']['en_examen'];
    $doublons += $b['doublons'];
}
haut('Tableau de bord', $u, 'tableau');
echo '<h1>Tableau de bord</h1>';
if ($admin) {
    foreach (AdminService::branchesSansDeuxReferents() as $b) {
        echo '<div class="alerte attention">La ' . e($b['nom']) . ' compte ' . (int) $b['nb'] . ' référent(s) au lieu de 2.</div>';
    }
}
?>
<div class="grille">
<div class="carte"><div class="chiffre"><?= array_sum(array_column($stats, 'personnes')) ?></div>fiches</div>
<div class="carte"><div class="chiffre"><?= $attente ?></div><a href="<?= e(url('file')) ?>">liens en attente</a></div>
<div class="carte"><div class="chiffre"><?= $doublons ?></div><a href="<?= e(url('doublons')) ?>">doublons à traiter</a></div>
</div>
<div class="carte table"><table><caption class="muet">Liens par branche et par statut</caption>
<tr><th scope="col">Branche</th><th scope="col">Fiches</th><?php foreach (['declare', 'confirme', 'valide', 'en_examen', 'prive', 'rejete'] as $s) { echo '<th scope="col">' . badge($s) . '</th>'; } ?></tr>
<?php foreach ($stats as $b) { ?>
<tr><td><?= e($b['nom']) ?></td><td><?= (int) $b['personnes'] ?></td><?php foreach ($b['liens'] as $n) { echo '<td>' . (int) $n . '</td>'; } ?></tr>
<?php } ?>
</table></div>
<h2>Dernières actions</h2>
<div class="carte table"><table><tr><th scope="col">Date (UTC)</th><th scope="col">Action</th><th scope="col">Fiche</th></tr>
<?php foreach (SuiviService::journal($portee, [], 10, 0) as $j) { ?>
<tr><td><?= e($j['cree_le']) ?></td><td><?= e($j['action']) ?></td><td><?= e($j['entite_type']) ?> <?= e($j['entite_id']) ?></td></tr>
<?php } ?>
</table></div>
<?php bas();
