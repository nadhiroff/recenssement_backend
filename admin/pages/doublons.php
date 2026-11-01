<?php
declare(strict_types=1);

use App\Services\DoublonService;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['faire'] ?? '') === 'distinct') {
        action(static fn() => DoublonService::distinct($u, $id), 'Paire déclarée distincte : elle ne sera plus proposée.', 'doublons');
    }
    action(static fn() => DoublonService::fusionner($u, $id, (int) ($_POST['conservee'] ?? 0)), 'Fiches fusionnées.', 'doublons');
}

$paires = DoublonService::liste($u, 'a_traiter', 50, 0);
haut('Doublons', $u, 'doublons');
echo '<h1>Doublons à traiter</h1>';
if ($paires === []) {
    echo '<div class="alerte">Aucune paire à traiter.</div>';
}
$fiche = static function (array $p): void {
    echo '<div><strong>' . e($p['nom_complet']) . '</strong> <span class="muet">n° ' . (int) $p['id'] . '</span>';
    foreach ($p['noms'] ?? [] as $n) {
        echo '<br><span class="muet">' . e($n['type']) . ' : ' . e($n['valeur']) . '</span>';
    }
    echo '<br>Naissance : ' . e($p['annee_naissance'] ?? '—') . ' (' . e($p['precision_naissance'] ?? '') . ')';
    echo '<br>Père : ' . e($p['pere'] ?? '—') . '<br>Mère : ' . e($p['mere'] ?? '—') . '</div>';
};
foreach ($paires as $d) { ?>
<div class="carte"><p class="muet">Score <?= (int) $d['score'] ?> : <?= e(implode(', ', array_keys($d['detail'] ?? []))) ?></p>
<div class="deux"><?php $fiche($d['a']); $fiche($d['b']); ?></div>
<form class="ligne" method="post" action="<?= e(url('doublons')) ?>" data-confirmer="Fusionner ces deux fiches ?"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
<select name="conservee" aria-label="Fiche conservée"><option value="<?= (int) $d['a']['id'] ?>">Garder n° <?= (int) $d['a']['id'] ?></option><option value="<?= (int) $d['b']['id'] ?>">Garder n° <?= (int) $d['b']['id'] ?></option></select>
<button>Fusionner</button></form>
<form class="ligne" method="post" action="<?= e(url('doublons')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="faire" value="distinct"><button class="contour">Personnes distinctes</button></form>
</div>
<?php }
bas();
