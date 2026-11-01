<?php
declare(strict_types=1);

use App\Core\ApiException;
use App\Services\FoyerService;

const PAR_PAGE = 6;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ordre = array_map('intval', explode(',', (string) ($_POST['ordre'] ?? '')));
    action(static fn() => FoyerService::ordonner($u, (int) ($_POST['epoux'] ?? 0), $ordre), 'Ordre des épouses mis à jour.', 'foyers');
}

$total = FoyerService::totalPeres();
$page = max(1, (int) ($_GET['n'] ?? 1));
$peres = FoyerService::peresPolygames(PAR_PAGE, ($page - 1) * PAR_PAGE);
haut('Foyers', $u, 'foyers', 'L\'ordre des épouses, à vérifier avec les aînés');
if ($peres === []) {
    echo '<div class="carte vide">Aucun père n\'a plusieurs épouses enregistrées.</div>';
}
echo '<div class="deux">';
foreach ($peres as $p) {
    $ids = array_map('intval', array_column($p['unions'], 'id'));
    echo '<section class="carte"><h2>' . e($p['epoux']['nom_complet']) . '</h2>';
    foreach ($p['unions'] as $i => $un) {
        $nouvel = $ids;
        $deplacer = static function (int $vers) use ($nouvel, $i): string {
            [$nouvel[$i], $nouvel[$vers]] = [$nouvel[$vers], $nouvel[$i]];
            return implode(',', $nouvel);
        };
        echo '<div class="action-journal"><span><strong>' . ($i + 1) . (($i + 1) === 1 ? 're' : 'e') . ' épouse</strong> : ' . e($p['fiches'][(int) $un['epouse_id']]['nom_complet'] ?? '') . '</span><span class="outils">';
        foreach ([[$i - 1, 'Monter', $i > 0], [$i + 1, 'Descendre', $i < count($ids) - 1]] as [$vers, $lib, $actif]) {
            if ($actif) {
                echo '<form method="post" action="' . e(url('foyers')) . '">' . champCsrf() . '<input type="hidden" name="epoux" value="' . (int) $p['epoux']['id'] . '"><input type="hidden" name="ordre" value="' . e($deplacer($vers)) . '"><button class="contour petit">' . $lib . '</button></form>';
            }
        }
        echo '</span></div>';
    }
    echo '</section>';
}
echo '</div>' . pagination('foyers', $page, PAR_PAGE, $total);
bas();
