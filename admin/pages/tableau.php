<?php
declare(strict_types=1);

use App\Services\AdminService;
use App\Services\SuiviService;

$stats = SuiviService::stats(null);
$fiches = array_sum(array_column($stats, 'personnes'));
$totaux = ['declare' => 0, 'confirme' => 0, 'valide' => 0, 'en_examen' => 0, 'prive' => 0, 'rejete' => 0];
foreach ($stats as $b) {
    foreach ($b['liens'] as $s => $n) {
        $totaux[$s] += $n;
    }
}
$utiles = array_sum($totaux) - $totaux['rejete'];
$part = $utiles > 0 ? (int) round($totaux['valide'] / $utiles * 100) : 0;
$manque = AdminService::branchesSansDeuxReferents();
haut('Tableau de bord', $u, 'tableau', 'Vue d\'ensemble du recensement de la famille ZOCLI');
if ($manque !== []) {
    echo '<div class="alerte attention">Référents à compléter : ' . e(implode(', ', array_map(static fn(array $b): string => $b['nom'] . ' (' . $b['nb'] . ' sur 2)', $manque))) . '. <a href="' . e(url('utilisateurs')) . '">Attribuer les rôles</a></div>';
}
?>
<div class="tuiles">
  <div class="tuile"><b><?= $fiches ?></b><span>personnes recensées</span></div>
  <div class="tuile or"><b><?= $part ?> %</b><span>des liens validés</span></div>
  <a class="tuile terre" href="<?= e(url('file')) ?>"><b><?= $totaux['confirme'] + $totaux['en_examen'] ?></b><span>liens à décider</span></a>
  <a class="tuile info" href="<?= e(url('doublons')) ?>"><b><?= array_sum(array_column($stats, 'doublons')) ?></b><span>doublons à traiter</span></a>
</div>
<div class="deux large remplir">
  <section class="carte">
    <h2>Avancement par branche</h2>
    <?php foreach ($stats as $b) { $total = array_sum($b['liens']); ?>
    <div class="branche"><div><strong><?= e($b['nom']) ?></strong><br><span class="muet"><?= (int) $b['personnes'] ?> fiches</span></div><?= barreStatuts($b['liens']) ?><span class="muet"><?= (int) $b['liens']['valide'] ?> / <?= $total ?></span></div>
    <?php } ?>
    <div class="legende"><?php foreach ($totaux as $s => $n) { echo badge($s) . ' ' . (int) $n . ' '; } ?></div>
  </section>
  <section class="carte">
    <h2>Dernières actions</h2>
    <?php foreach (SuiviService::journal(null, [], 7, 0) as $j) { ?>
    <div class="action-journal"><span><?= e(libelleAction($j['action'])) ?></span><span class="muet"><?= e(substr($j['cree_le'], 5, 11)) ?></span></div>
    <?php } ?>
  </section>
</div>
<?php bas();
