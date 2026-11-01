<?php
declare(strict_types=1);

use App\Services\AdminService;
use App\Services\ParametreService;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valeurs = array_diff_key($_POST, ['csrf' => 1]);
    action(static fn() => AdminService::modifierParametres($u, $valeurs), 'Paramètres enregistrés : ils s\'appliquent immédiatement.', 'parametres');
}

$v = ParametreService::tous();
$champ = static function (string $cle, string $libelle, string $aide = '') use ($v): void {
    echo '<div><label for="' . e($cle) . '">' . e($libelle) . '</label><input id="' . e($cle) . '" name="' . e($cle) . '" type="number" inputmode="numeric" value="' . e($v[$cle] ?? '') . '" required>'
        . ($aide !== '' ? '<div class="aide">' . e($aide) . '</div>' : '') . '</div>';
};
$bascule = static function (string $cle, string $libelle, string $aide) use ($v): void {
    echo '<input type="hidden" name="' . e($cle) . '" value="0"><label class="bascule"><input type="checkbox" name="' . e($cle) . '" value="1"' . (($v[$cle] ?? '0') === '1' ? ' checked' : '') . '><span>' . e($libelle) . '<span class="aide"><br>' . e($aide) . '</span></span></label>';
};
haut('Paramètres', $u, 'parametres', 'Les règles du recensement, modifiables sans intervention technique', '<button form="reglages">Enregistrer</button>');
?>
<form id="reglages" method="post" action="<?= e(url('parametres')) ?>" class="deux"><?= champCsrf() ?>
  <section class="carte"><h2>Ressemblance entre deux fiches</h2>
    <p class="muet">Points ajoutés quand deux fiches ont ce point commun.</p>
    <div class="grille-champs">
      <?php $champ('score_prenom_commun', 'Prénom commun'); $champ('score_meme_pere', 'Même père'); $champ('score_meme_branche', 'Même branche'); $champ('score_naissance_5ans', 'Naissance proche'); $champ('score_meme_mere', 'Même mère'); ?>
    </div>
  </section>
  <section class="carte"><h2>Seuils de décision</h2>
    <div class="grille-champs">
      <?php $champ('seuil_proposition', 'Proposer une fiche', 'Score à partir duquel une fiche est proposée à l\'inscrit.'); $champ('seuil_doublon', 'Signaler un doublon', 'Score à partir duquel une paire est signalée.'); $champ('ecart_annees_max', 'Naissance proche (années)', 'Écart maximal entre deux années.'); ?>
    </div>
  </section>
  <section class="carte"><h2>Relances</h2>
    <div class="grille-champs">
      <?php $champ('delai_relance_jours', 'Première relance (jours)', 'Après la déclaration d\'un lien non confirmé.'); $champ('delai_relance_suivante_jours', 'Relances suivantes (jours)'); $champ('nb_relances_max', 'Nombre maximal'); ?>
    </div>
  </section>
  <section class="carte"><h2>Décisions du conseil de famille</h2>
    <?php $bascule('arbre_visible_membres', 'Arbre consultable par les membres', 'Désactivé, seuls les référents voient l\'arbre dans l\'application.'); ?>
    <?php $bascule('doublon_prenom_requis', 'Exiger un prénom commun pour un doublon', 'Évite de signaler les frères et sœurs proches en âge.'); ?>
    <label for="generations_lignee_feminine">Descendants par les filles ZOCLI</label>
    <select id="generations_lignee_feminine" name="generations_lignee_feminine">
      <?php foreach (['-1' => 'Tous les descendants', '0' => 'Les filles, sans leurs enfants', '1' => 'Une génération après elles', '2' => 'Deux générations après elles', '3' => 'Trois générations après elles'] as $k => $t) { echo '<option value="' . $k . '"' . (($v['generations_lignee_feminine'] ?? '-1') === (string) $k ? ' selected' : '') . '>' . $t . '</option>'; } ?>
    </select>
    <div class="aide">Limite l'affichage de l'arbre, pas l'inscription.</div>
  </section>
</form>
<?php bas();
