<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PersonneRepository;
use App\Services\JournalService;
use App\Services\LienService;
use App\Services\PermissionService;

$l = LienService::charger((int) ($_GET['id'] ?? 0));
if (!PermissionService::couvreBranche($u, $l['branche_id'] === null ? null : (int) $l['branche_id'])) {
    exigerAdminPage($u);
}
if ($l['statut'] === 'prive') {
    JournalService::ecrire((int) $u['id'], 'personne.consultation_privee', 'personne', (int) $l['enfant_id'], $l['branche_id'] === null ? null : (int) $l['branche_id']);
}
$f = PersonneRepository::parIds([$l['parent_id'], $l['enfant_id']]);
$conflit = PermissionService::conflitInteret($u, $l);
$historique = Database::all("SELECT action, details, cree_le, utilisateur_id FROM journal_actions WHERE entite_type = 'lien' AND entite_id = ? ORDER BY id", [$l['id']]);
$invitations = Database::all('SELECT created_at, utilise_le, reponse FROM invitations_confirmation WHERE lien_id = ? ORDER BY id', [$l['id']]);
$actions = LienService::TRANSITIONS[$l['statut']];
haut('Lien', $u, 'file');
?>
<h1><?= e($f[(int) $l['parent_id']]['nom_complet'] ?? '') ?> → <?= e($f[(int) $l['enfant_id']]['nom_complet'] ?? '') ?></h1>
<div class="carte"><p><?= $l['role_parent'] === 'pere' ? 'Père' : 'Mère' ?> · <?= badge($l['statut']) ?></p>
<p class="muet">Déclaré le <?= e($l['declare_le']) ?><?= $l['confirme_le'] ? ' · confirmé le ' . e($l['confirme_le']) : '' ?><?= $l['valide_le'] ? ' · validé le ' . e($l['valide_le']) : '' ?></p>
<?php if ($l['motif_prive']) { echo '<p>Motif (privé) : ' . e($l['motif_prive']) . '</p>'; } if ($l['motif_rejet']) { echo '<p>Motif de rejet : ' . e($l['motif_rejet']) . '</p>'; } ?>
<?php foreach ($invitations as $i) { echo '<p class="muet">Invitation du ' . e($i['created_at']) . ' : ' . e($i['reponse'] ?? ($i['utilise_le'] ? 'ne sait pas' : 'sans réponse')) . '</p>'; } ?>
</div>
<?php if ($conflit) { ?>
<div class="alerte attention">Ce lien vous concerne, vous ou un de vos enfants : il doit être traité par un autre référent ou l'administrateur.</div>
<?php } elseif ($actions !== []) { ?>
<div class="carte"><h2>Décision</h2>
<?php if (in_array('valide', $actions, true)) { ?><form class="ligne" method="post" action="<?= e(url('file')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="faire" value="valider"><button>Valider</button></form><?php } ?>
<?php if (in_array('en_examen', $actions, true)) { ?><form class="ligne" method="post" action="<?= e(url('file')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="faire" value="examen"><button class="contour">Consulter les aînés</button></form><?php } ?>
<?php if (in_array('prive', $actions, true)) { ?><form class="ligne" method="post" action="<?= e(url('file')) ?>"><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="faire" value="prive"><input name="motif" placeholder="Motif (référents seulement)" required aria-label="Motif du passage en privé"><button class="contour">Passer en privé</button></form><?php } ?>
<?php if (in_array('rejete', $actions, true)) { ?><form class="ligne" method="post" action="<?= e(url('file')) ?>" data-confirmer="Rejeter ce lien ? Cette décision est définitive."><?= champCsrf() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="faire" value="rejeter"><input name="motif" placeholder="Motif du rejet" required aria-label="Motif du rejet"><button class="danger">Rejeter</button></form><?php } ?>
</div>
<?php } ?>
<h2>Historique</h2>
<div class="carte table"><table><tr><th scope="col">Date (UTC)</th><th scope="col">Action</th><th scope="col">Détails</th></tr>
<?php foreach ($historique as $h) { echo '<tr><td>' . e($h['cree_le']) . '</td><td>' . e($h['action']) . '</td><td class="muet">' . e($h['details']) . '</td></tr>'; } ?>
</table></div>
<?php bas();
