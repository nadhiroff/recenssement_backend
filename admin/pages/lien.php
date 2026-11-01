<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\JournalService;
use App\Services\LienService;
use App\Services\PermissionService;

$l = LienService::charger((int) ($_GET['id'] ?? 0));
if ($l['statut'] === 'prive') {
    JournalService::ecrire((int) $u['id'], 'personne.consultation_privee', 'personne', (int) $l['enfant_id'], $l['branche_id'] === null ? null : (int) $l['branche_id']);
}
$f = PersonneRepository::parIds([$l['parent_id'], $l['enfant_id']]);
$conflit = PermissionService::conflitInteret($u, $l);
$historique = Database::all("SELECT action, cree_le FROM journal_actions WHERE entite_type = 'lien' AND entite_id = ? ORDER BY id DESC LIMIT 8", [$l['id']]);
$invitations = Database::all('SELECT created_at, utilise_le, reponse FROM invitations_confirmation WHERE lien_id = ? ORDER BY id', [$l['id']]);
$actions = LienService::TRANSITIONS[$l['statut']];
$reponses = ['confirme' => 'a confirmé', 'refuse' => 'a refusé'];
$form = static function (string $faire) use ($l): string {
    return champCsrf() . '<input type="hidden" name="id" value="' . (int) $l['id'] . '"><input type="hidden" name="faire" value="' . e($faire) . '">';
};
haut(($f[(int) $l['parent_id']]['nom_complet'] ?? '') . ' → ' . ($f[(int) $l['enfant_id']]['nom_complet'] ?? ''), $u, 'file', $l['role_parent'] === 'pere' ? 'Lien père et enfant' : 'Lien mère et enfant', '<a class="bouton contour" href="' . e(url('file')) . '">Retour à la liste</a>');
?>
<div class="deux large">
  <section class="carte"><h2>Parcours du lien</h2>
    <p><?= badge($l['statut']) ?></p>
    <p class="muet">Déclaré le <?= e(substr($l['declare_le'], 0, 16)) ?><?= $l['confirme_le'] ? ' · confirmé le ' . e(substr($l['confirme_le'], 0, 16)) : '' ?><?= $l['valide_le'] ? ' · validé le ' . e(substr($l['valide_le'], 0, 16)) : '' ?></p>
    <?php if ($l['motif_prive']) { echo '<p>Motif (privé) : ' . e($l['motif_prive']) . '</p>'; } if ($l['motif_rejet']) { echo '<p>Motif de rejet : ' . e($l['motif_rejet']) . '</p>'; } ?>
    <?php foreach ($invitations as $i) { echo '<p class="muet">Invitation du ' . e(substr($i['created_at'], 0, 10)) . ' : le proche ' . e($reponses[$i['reponse'] ?? ''] ?? ($i['utilise_le'] ? 'ne sait pas' : 'n\'a pas encore répondu')) . '</p>'; } ?>
    <h2>Historique</h2>
    <?php foreach ($historique as $h) { echo '<div class="action-journal"><span>' . e(libelleAction($h['action'])) . '</span><span class="muet">' . e(substr($h['cree_le'], 0, 16)) . '</span></div>'; } ?>
  </section>
  <section class="carte"><h2>Décision</h2>
    <?php if ($conflit) { ?>
    <div class="alerte attention">Ce lien vous concerne, vous ou un de vos enfants : il doit être traité par un autre référent.</div>
    <?php } elseif ($actions === []) { echo '<p class="muet">Ce lien est clos.</p>'; } else { ?>
    <div class="outils">
    <?php if (in_array('valide', $actions, true)) { ?><form method="post" action="<?= e(url('file')) ?>"><?= $form('valider') ?><button>Valider</button></form><?php } ?>
    <?php if (in_array('en_examen', $actions, true)) { ?><form method="post" action="<?= e(url('file')) ?>"><?= $form('examen') ?><button class="contour">Consulter les aînés</button></form><?php } ?>
    <?php if (in_array('prive', $actions, true)) { ?><button type="button" class="contour" data-ouvrir="f-prive">Passer en privé</button><?php } ?>
    <?php if (in_array('rejete', $actions, true)) { ?><button type="button" class="danger" data-ouvrir="f-rejet">Rejeter</button><?php } ?>
    </div>
    <?php } ?>
  </section>
</div>
<dialog id="f-prive"><div class="fenetre"><h2>Passer en privé</h2><p class="muet">Le lien et la fiche disparaissent pour les membres.</p>
<form method="post" action="<?= e(url('file')) ?>"><?= $form('prive') ?><label for="m-p">Motif, visible des référents seulement</label><textarea id="m-p" name="motif" rows="2" required></textarea>
<div class="boutons"><button type="button" class="contour" data-fermer>Annuler</button><button>Confirmer</button></div></form></div></dialog>
<dialog id="f-rejet"><div class="fenetre"><h2>Rejeter ce lien</h2><p class="muet">Cette décision est définitive et le déclarant verra le motif.</p>
<form method="post" action="<?= e(url('file')) ?>"><?= $form('rejeter') ?><label for="m-r">Motif du rejet</label><textarea id="m-r" name="motif" rows="2" required></textarea>
<div class="boutons"><button type="button" class="contour" data-fermer>Annuler</button><button class="danger">Rejeter</button></div></form></div></dialog>
<?php bas();
