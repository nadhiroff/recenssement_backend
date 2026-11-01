<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\DoublonService;

const PAR_PAGE = 2;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['faire'] ?? '') === 'distinct') {
        action(static fn() => DoublonService::distinct($u, $id), 'Paire déclarée distincte : elle ne sera plus proposée.', 'doublons');
    }
    action(static fn() => DoublonService::fusionner($u, $id, (int) ($_POST['conservee'] ?? 0)), 'Fiches fusionnées.', 'doublons');
}

$total = (int) Database::value("SELECT COUNT(*) FROM doublons_suspects WHERE statut = 'a_traiter'");
$page = max(1, (int) ($_GET['n'] ?? 1));
$paires = DoublonService::liste($u, 'a_traiter', PAR_PAGE, ($page - 1) * PAR_PAGE);
$criteres = ['prenom' => 'prénom', 'branche' => 'branche', 'pere' => 'père', 'mere' => 'mère', 'naissance' => 'naissance'];
haut('Doublons', $u, 'doublons', $total . ' paire(s) à examiner');
if ($paires === []) {
    echo '<div class="carte vide">Aucune paire à traiter.</div>';
}
$fiche = static function (array $p): void {
    $autres = array_filter(array_map(static fn(array $n): string => $n['valeur'], array_filter($p['noms'] ?? [], static fn(array $n): bool => !$n['principal'])));
    echo '<div><strong>' . e($p['nom_complet']) . '</strong> <span class="muet">n° ' . (int) $p['id'] . '</span>';
    if ($autres !== []) {
        echo '<br><span class="muet">' . e(implode(', ', $autres)) . '</span>';
    }
    echo '<br><span class="muet">Né(e) : ' . e($p['annee_naissance'] ?? '—') . ' · Père : ' . e($p['pere'] ?? '—') . ' · Mère : ' . e($p['mere'] ?? '—') . '</span></div>';
};
foreach ($paires as $d) { ?>
<section class="carte">
<h2>Score <?= (int) $d['score'] ?> <span class="muet">: <?= e(implode(', ', array_map(static fn(string $c): string => $criteres[$c] ?? $c, array_keys($d['detail'] ?? [])))) ?></span></h2>
<div class="deux"><?php $fiche($d['a']); $fiche($d['b']); ?></div>
<div class="boutons">
<form class="ligne" method="post" action="<?= e(url('doublons')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="faire" value="distinct"><button class="contour">Personnes distinctes</button></form>
<form class="ligne" method="post" action="<?= e(url('doublons')) ?>" data-confirmer="Fusionner ces deux fiches ?" data-oui="Oui, fusionner"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
<select name="conservee" aria-label="Fiche conservée"><option value="<?= (int) $d['a']['id'] ?>">Garder n° <?= (int) $d['a']['id'] ?></option><option value="<?= (int) $d['b']['id'] ?>">Garder n° <?= (int) $d['b']['id'] ?></option></select>
<button>Fusionner</button></form>
</div></section>
<?php }
echo pagination('doublons', $page, PAR_PAGE, $total);
bas();
