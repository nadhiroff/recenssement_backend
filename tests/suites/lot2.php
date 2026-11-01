<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\LienService;
use App\Services\MatchingService;
use App\Services\ParametreService;
use App\Services\TacheService;

section('Lot 2 — machine à états');

test('Transitions autorisées et interdites', function () {
    vrai(LienService::transitionAutorisee('declare', 'confirme'));
    vrai(LienService::transitionAutorisee('confirme', 'valide'));
    vrai(LienService::transitionAutorisee('en_examen', 'valide'));
    vrai(!LienService::transitionAutorisee('declare', 'valide'), 'declare → valide');
    vrai(!LienService::transitionAutorisee('rejete', 'declare'), 'rejeté terminal');
    vrai(!LienService::transitionAutorisee('prive', 'valide'), 'privé → valide');
});

test('Un lien déclaré ne peut pas être validé directement (409)', function () {
    statut(api('POST', '/liens/15/valider', [], connecter('+2290100000012')), 409);
    egal('declare', Database::value('SELECT statut FROM liens_parente WHERE id = 15'));
});

test('Rejet : motif obligatoire, puis lien terminal et nouveau lien possible', function () {
    $j = connecter('+2290100000013');
    statut(api('POST', '/liens/18/rejeter', [], connecter('+2290100000010')), 403, 'autre branche');
    statut(api('POST', '/liens/18/rejeter', [], $j), 422);
    statut(api('POST', '/liens/18/rejeter', ['motif' => 'Erreur de personne'], $j), 200);
    statut(api('POST', '/liens/18/examen', [], $j), 409);
    $id = (int) Database::insert('liens_parente', ['parent_id' => 12, 'enfant_id' => 18, 'role_parent' => 'pere', 'declarant_utilisateur_id' => 11]);
    vrai($id > 18, 'nouveau lien');
    egal(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = 11 AND type = 'lien_rejete'"));
});

section('Lot 2 — conflit d\'intérêts');

test('Recette 7 : un référent ne valide pas un lien qui le concerne, un autre le peut', function () {
    // Lien 16 : Kossi (référent) père d'Edem
    egal('conflit_interet', statut(api('POST', '/liens/16/valider', [], connecter('+2290100000009')), 403)['error']['code']);
    egal('confirme', Database::value('SELECT statut FROM liens_parente WHERE id = 16'));
    egal(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = 3 AND type = 'orientation' AND lien_id = 16"), 'orienté');
    statut(api('POST', '/liens/16/valider', [], connecter('+2290100000010')), 200);
    egal('valide', Database::value('SELECT statut FROM liens_parente WHERE id = 16'));
});

test('Recette 7 : même règle pour un lien qui concerne ses enfants', function () {
    // Enfant de Dela (17), elle-même fille de Kossi : un lien sur Dela concerne un enfant de Kossi
    $id = Database::insert('personnes', ['sexe' => 'F', 'branche_id' => 1]);
    $lien = Database::insert('liens_parente', ['parent_id' => 17, 'enfant_id' => $id, 'role_parent' => 'mere', 'statut' => 'confirme', 'declarant_utilisateur_id' => 10]);
    statut(api('POST', "/liens/$lien/valider", [], connecter('+2290100000009')), 403);
    statut(api('POST', "/liens/$lien/examen", [], connecter('+2290100000009')), 403, 'examen aussi');
    statut(api('POST', "/liens/$lien/rejeter", ['motif' => 'x'], connecter('+2290100000009')), 403, 'rejet aussi');
    statut(api('POST', "/liens/$lien/valider", [], connecter('+2290100000010')), 200);
});

test('Conflit : file du référent signale les liens à traiter par un autre', function () {
    $lien = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 1]);
    $l = (int) Database::insert('liens_parente', ['parent_id' => 9, 'enfant_id' => $lien, 'role_parent' => 'pere', 'statut' => 'confirme', 'declarant_utilisateur_id' => 2]);
    $file = statut(api('GET', '/referent/file', null, connecter('+2290100000009')), 200)['data'];
    $ligne = array_values(array_filter($file, static fn(array $x): bool => $x['id'] === $l))[0];
    egal(true, $ligne['conflit']);
});

test('L\'admin est soumis à la même règle', function () {
    $conflit = \App\Services\PermissionService::conflitInteret(['personne_id' => 25], ['parent_id' => 25, 'enfant_id' => 3]);
    vrai($conflit);
    $idP = Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 1]);
    $l = Database::insert('liens_parente', ['parent_id' => 25, 'enfant_id' => $idP, 'role_parent' => 'pere', 'statut' => 'confirme']);
    statut(api('POST', "/liens/$l/valider", [], jetonDirect(1)), 403);
});

test('Référent : droits limités à sa branche ; membre interdit', function () {
    statut(api('GET', '/referent/file', null, connecter('+2290100000016')), 403);
    statut(api('GET', '/referent/file?branche=2', null, connecter('+2290100000010')), 403);
    statut(api('POST', '/liens/22/valider', [], connecter('+2290100000012')), 403);
    statut(api('POST', '/liens/22/valider', [], connecter('+2290100000016')), 403);
    egal('1', (string) count(array_unique(array_column(statut(api('GET', '/referent/file?branche=1', null, connecter('+2290100000010')), 200)['data'], 'branche_id'))));
});

test('Validation du père et de la mère : l\'union passe en validée', function () {
    $e = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 3]);
    $u = (int) Database::insert('unions', ['epoux_id' => 23, 'epouse_id' => 24]);
    $a = Database::insert('liens_parente', ['parent_id' => 23, 'enfant_id' => $e, 'role_parent' => 'pere', 'statut' => 'confirme']);
    $b = Database::insert('liens_parente', ['parent_id' => 24, 'enfant_id' => $e, 'role_parent' => 'mere', 'statut' => 'confirme']);
    Database::run('UPDATE referents_branche SET branche_id = 3 WHERE utilisateur_id = 6');
    Database::run("UPDATE utilisateurs SET personne_id = NULL WHERE id = 6");
    $j = jetonDirect(6);
    statut(api('POST', "/liens/$a/valider", [], $j), 200);
    egal('declare', Database::value('SELECT statut FROM unions WHERE id = ?', [$u]));
    statut(api('POST', "/liens/$b/valider", [], $j), 200);
    egal('valide', Database::value('SELECT statut FROM unions WHERE id = ?', [$u]));
    Database::run('UPDATE utilisateurs SET personne_id = 23 WHERE id = 6');
});

section('Lot 2 — confirmation par un proche');

$lienDeclare = static function (int $declarantUser = 12): int {
    $e = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 1]);
    Database::insert('noms_personne', ['personne_id' => $e, 'type' => 'naissance', 'valeur' => 'Enfant', 'valeur_normalisee' => 'ENFANT', 'principal' => 1]);
    return (int) Database::insert('liens_parente', ['parent_id' => 11, 'enfant_id' => $e, 'role_parent' => 'pere', 'declarant_utilisateur_id' => $declarantUser]);
};

test('Invitation : URL wa.me à jeton unique, jeton haché en base', function () use ($lienDeclare) {
    $l = $lienDeclare();
    $r = statut(api('POST', "/liens/$l/invitations", ['telephone' => '+2290166000001'], connecter('+2290100000020')), 201);
    vrai(str_starts_with($r['data']['whatsapp_url'], 'https://wa.me/2290166000001?text='), 'wa.me');
    vrai(str_contains(rawurldecode($r['data']['whatsapp_url']), 'est bien le père de'), 'message');
    $token = substr($r['data']['url'], strpos($r['data']['url'], '=') + 1);
    egal(1, (int) Database::value('SELECT COUNT(*) FROM invitations_confirmation WHERE token_hash = ?', [hash('sha256', $token)]));
    egal(0, (int) Database::value('SELECT COUNT(*) FROM invitations_confirmation WHERE token_hash = ?', [$token]));
});

test('Invitation : le lien s\'ouvre sur le téléphone du proche, pas sur localhost', function () use ($lienDeclare) {
    $r = statut(api('POST', '/liens/' . $lienDeclare() . '/invitations', [], connecter('+2290100000020')), 201);
    vrai(str_starts_with($r['data']['url'], 'http://127.0.0.1:' . TEST_PORT . '/confirmation.php?t='), $r['data']['url']);
});

test('Invitation : réservée au déclarant', function () use ($lienDeclare) {
    statut(api('POST', '/liens/' . $lienDeclare() . '/invitations', [], connecter('+2290100000017')), 404);
});

$inviter = static function (int $lien): string {
    $r = statut(api('POST', "/liens/$lien/invitations", [], connecter('+2290100000020')), 201);
    return substr($r['data']['url'], strpos($r['data']['url'], '=') + 1);
};

test('Invitation : on ne peut pas s\'inviter soi-même', function () use ($lienDeclare) {
    statut(api('POST', '/liens/' . $lienDeclare() . '/invitations', ['telephone' => '+2290100000020'], connecter('+2290100000020')), 422);
});

test('Confirmation : Oui fait passer le lien à confirmé, sans compte, une seule fois', function () use ($lienDeclare, $inviter) {
    $l = $lienDeclare();
    $t = $inviter($l);
    $v = statut(api('GET', "/confirmations/$t"), 200);
    egal('Séna ZOCLI', $v['data']['parent']);
    vrai(!str_contains($v['brut'], '+229'), 'téléphone exposé');
    statut(api('POST', "/confirmations/$t", ['reponse' => 'oui']), 200);
    egal('confirme', Database::value('SELECT statut FROM liens_parente WHERE id = ?', [$l]));
    statut(api('POST', "/confirmations/$t", ['reponse' => 'oui']), 409, 'réutilisation');
});

test('Confirmation : Non envoie en examen, Je ne sais pas ne change rien', function () use ($lienDeclare, $inviter) {
    $l = $lienDeclare();
    statut(api('POST', '/confirmations/' . $inviter($l), ['reponse' => 'ne_sait_pas']), 200);
    egal('declare', Database::value('SELECT statut FROM liens_parente WHERE id = ?', [$l]));
    statut(api('POST', '/confirmations/' . $inviter($l), ['reponse' => 'non']), 200);
    egal('en_examen', Database::value('SELECT statut FROM liens_parente WHERE id = ?', [$l]));
});

test('Confirmation : le déclarant ne confirme pas son propre lien', function () use ($lienDeclare, $inviter) {
    $l = $lienDeclare();
    statut(api('POST', '/confirmations/' . $inviter($l), ['reponse' => 'oui'], connecter('+2290100000020')), 403);
    egal('declare', Database::value('SELECT statut FROM liens_parente WHERE id = ?', [$l]));
});

test('Confirmation : invitation expirée ou jeton inconnu', function () use ($lienDeclare, $inviter) {
    $t = $inviter($lienDeclare());
    Database::run('UPDATE invitations_confirmation SET expire_le = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE token_hash = ?', [hash('sha256', $t)]);
    statut(api('GET', "/confirmations/$t"), 410);
    statut(api('GET', '/confirmations/' . str_repeat('a', 43)), 404);
});

test('Page web de confirmation : question affichée, réponse enregistrée, échappement', function () use ($lienDeclare, $inviter) {
    $l = $lienDeclare();
    $t = $inviter($l);
    $url = 'http://127.0.0.1:' . TEST_PORT . "/confirmation.php?t=$t";
    $html = (string) file_get_contents($url);
    vrai(str_contains($html, 'Confirmez-vous que') && str_contains($html, 'Séna ZOCLI'), 'page');
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => 'reponse=oui']]);
    vrai(str_contains((string) file_get_contents($url, false, $c), 'Merci'), 'merci');
    egal('confirme', Database::value('SELECT statut FROM liens_parente WHERE id = ?', [$l]));
});

section('Lot 2 — alertes');

$dernierPush = static function (): array {
    $l = file(BASE_PATH . '/storage/logs/push.log', FILE_IGNORE_NEW_LINES) ?: [];
    return json_decode(substr((string) end($l), strpos((string) end($l), '{')), true) ?? [];
};

test('Alertes : appareil enregistré, oublié à la déconnexion, jeton invalide refusé', function () {
    $j = connecter('+2290100000020');
    statut(api('POST', '/me/appareils', ['token' => 'nimporte', 'plateforme' => 'ios'], $j), 422);
    statut(api('POST', '/me/appareils', ['token' => 'ExponentPushToken[deconnexion]', 'plateforme' => 'ios'], $j), 200);
    statut(api('POST', '/me/appareils', ['token' => 'ExponentPushToken[deconnexion]', 'plateforme' => 'ios'], $j), 200, 'sans doublon');
    egal(1, (int) Database::value("SELECT COUNT(*) FROM appareils_push WHERE token = 'ExponentPushToken[deconnexion]'"));
    statut(api('POST', '/auth/logout', ['push_token' => 'ExponentPushToken[deconnexion]'], $j), 200);
    egal(0, (int) Database::value("SELECT COUNT(*) FROM appareils_push WHERE token = 'ExponentPushToken[deconnexion]'"));
});

test('Alertes : un téléphone passé à un autre compte ne prévient plus l\'ancien', function () {
    statut(api('POST', '/me/appareils', ['token' => 'ExponentPushToken[partage]', 'plateforme' => 'ios'], connecter('+2290100000020')), 200);
    statut(api('POST', '/me/appareils', ['token' => 'ExponentPushToken[partage]', 'plateforme' => 'ios'], connecter('+2290100000017')), 200);
    egal(10, (int) Database::value("SELECT utilisateur_id FROM appareils_push WHERE token = 'ExponentPushToken[partage]'"));
    Database::run("DELETE FROM appareils_push WHERE token = 'ExponentPushToken[partage]'");
});

test('Alertes : confirmation prévient déclarant et référents, texte sans aucun nom', function () use ($lienDeclare, $inviter, $dernierPush) {
    statut(api('POST', '/me/appareils', ['token' => 'ExponentPushToken[referent]', 'plateforme' => 'ios'], connecter('+2290100000010')), 200);
    $l = $lienDeclare();
    statut(api('POST', '/confirmations/' . $inviter($l), ['reponse' => 'oui']), 200);
    egal(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = 12 AND type = 'lien_confirme' AND lien_id = ?", [$l]), 'déclarant');
    egal(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = 3 AND type = 'a_traiter' AND lien_id = ?", [$l]), 'référent');
    $p = $dernierPush();
    egal('Un lien attend votre décision.', $p['body']);
    egal($l, $p['data']['lien_id']);
    vrai(str_contains((string) Database::value("SELECT message FROM notifications WHERE utilisateur_id = 3 AND lien_id = ?", [$l]), 'Séna'), 'détail dans l\'application');
    vrai(!str_contains((string) file_get_contents(BASE_PATH . '/storage/logs/push.log'), 'ZOCLI'), 'nom transmis à un service tiers');
    Database::run("DELETE FROM appareils_push WHERE token = 'ExponentPushToken[referent]'");
});

test('Alertes : une seule marquée lue, puis toutes ; jamais celles d\'un autre', function () {
    $j = connecter('+2290100000020');
    \App\Services\NotificationService::notifier(12, 'relance', 'Test 1', null, false);
    \App\Services\NotificationService::notifier(12, 'relance', 'Test 2', null, false);
    \App\Services\NotificationService::notifier(10, 'relance', 'Autre', null, false);
    $id = (int) Database::value("SELECT id FROM notifications WHERE message = 'Test 1'");
    statut(api('POST', "/notifications/$id/lu", [], $j), 200);
    vrai(Database::value('SELECT lu_le FROM notifications WHERE id = ?', [$id]) !== null, 'lue');
    egal(['Test 2'], array_values(array_intersect(['Test 1', 'Test 2'], array_column(statut(api('GET', '/notifications', null, $j), 200)['data'], 'message'))), 'une alerte vue disparaît');
    statut(api('POST', '/notifications/lu', [], $j), 200);
    egal(0, (int) Database::value('SELECT COUNT(*) FROM notifications WHERE utilisateur_id = 12 AND lu_le IS NULL'));
    egal([], statut(api('GET', '/notifications', null, $j), 200)['data']);
    egal(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE message = 'Autre' AND lu_le IS NULL"));
    statut(api('POST', "/notifications/" . Database::value("SELECT id FROM notifications WHERE message = 'Autre'") . '/lu', [], $j), 200);
    egal(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE message = 'Autre' AND lu_le IS NULL"), 'alerte d\'un autre');
});

section('Lot 2 — situations délicates');

test('Passer en privé : motif, fiche masquée aux membres, visible du référent', function () {
    $e = (int) Database::insert('personnes', ['sexe' => 'F', 'branche_id' => 2, 'telephone_whatsapp' => '+2290100000777']);
    Database::insert('noms_personne', ['personne_id' => $e, 'type' => 'naissance', 'valeur' => 'Délicate', 'valeur_normalisee' => 'DELICATE', 'principal' => 1]);
    Database::run('INSERT INTO noms_tokens (nom_id, token) SELECT id, valeur_normalisee FROM noms_personne WHERE personne_id = ?', [$e]);
    $l = (int) Database::insert('liens_parente', ['parent_id' => 12, 'enfant_id' => $e, 'role_parent' => 'pere', 'statut' => 'confirme', 'declarant_utilisateur_id' => 11]);
    $m = connecter('+2290100000018');
    $ref = connecter('+2290100000013');
    egal(1, count(api('GET', '/personnes/recherche?q=delicate', null, $m)['data']));
    statut(api('POST', "/liens/$l/prive", [], $ref), 422, 'motif');
    statut(api('POST', "/liens/$l/prive", ['motif' => 'Filiation contestée'], $ref), 200);
    egal([], api('GET', '/personnes/recherche?q=delicate', null, $m)['data']);
    statut(api('GET', "/personnes/$e", null, $m), 404);
    statut(api('GET', "/liens/$l", null, $m), 404);
    vrai(!str_contains(api('GET', '/mes-liens', null, $m)['brut'], 'Filiation contestée'), 'motif exposé');
    egal('Filiation contestée', statut(api('GET', "/liens/$l", null, $ref), 200)['data']['motif_prive']);
    // Retour en examen : la fiche redevient visible
    statut(api('POST', "/liens/$l/examen", [], $ref), 200);
    egal(1, count(api('GET', '/personnes/recherche?q=delicate', null, $m)['data']));
});

test('Rattachement à une branche : référent de la branche d\'origine, journalisé', function () {
    $p = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 1]);
    statut(api('POST', "/personnes/$p/branche", ['branche_id' => 2], connecter('+2290100000012')), 403);
    statut(api('POST', "/personnes/$p/branche", ['branche_id' => 2], connecter('+2290100000010')), 200);
    egal(2, (int) Database::value('SELECT branche_id FROM personnes WHERE id = ?', [$p]));
    egal(1, (int) Database::value("SELECT COUNT(*) FROM journal_actions WHERE action = 'personne.rattachement' AND entite_id = ?", [$p]));
});

section('Lot 2 — relances');

test('Relance à 60 jours (date simulée), puis tous les 30 jours, 3 fois maximum', function () use ($lienDeclare) {
    Database::run("UPDATE liens_parente SET statut = 'en_examen', nb_relances = 3 WHERE statut = 'declare'");
    $l = $lienDeclare();
    Database::run('UPDATE liens_parente SET declare_le = UTC_TIMESTAMP() - INTERVAL 59 DAY WHERE id = ?', [$l]);
    egal(0, TacheService::lancer('relances')['relances'], 'trop tôt');
    Database::run('UPDATE liens_parente SET declare_le = UTC_TIMESTAMP() - INTERVAL 61 DAY WHERE id = ?', [$l]);
    egal(1, TacheService::lancer('relances')['relances']);
    egal(1, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE lien_id = ? AND type = 'relance'", [$l]));
    egal(0, TacheService::lancer('relances')['relances'], 'pas deux fois de suite');
    Database::run('UPDATE liens_parente SET derniere_relance_le = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = ?', [$l]);
    egal(1, TacheService::lancer('relances')['relances'], '2e relance');
    Database::run('UPDATE liens_parente SET derniere_relance_le = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = ?', [$l]);
    egal(1, TacheService::lancer('relances')['relances'], '3e relance');
    Database::run('UPDATE liens_parente SET derniere_relance_le = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = ?', [$l]);
    egal(0, TacheService::lancer('relances')['relances'], 'plus de 3');
    egal(3, (int) Database::value('SELECT nb_relances FROM liens_parente WHERE id = ?', [$l]));
});

test('Relance : délai lu depuis les paramètres', function () use ($lienDeclare) {
    Database::run("UPDATE parametres SET valeur = '10' WHERE cle = 'delai_relance_jours'");
    ParametreService::viderCache();
    $l = $lienDeclare();
    Database::run('UPDATE liens_parente SET declare_le = UTC_TIMESTAMP() - INTERVAL 11 DAY WHERE id = ?', [$l]);
    egal(1, TacheService::lancer('relances')['relances']);
    Database::run("UPDATE parametres SET valeur = '60' WHERE cle = 'delai_relance_jours'");
    ParametreService::viderCache();
});

section('Lot 2 — doublons');

/** @return array<string, array> toutes les lignes des tables touchées, pour comparaison stricte */
$photo = static function (): array {
    $res = [];
    foreach (['personnes', 'liens_parente', 'unions', 'noms_personne', 'noms_tokens', 'declarations_tiers', 'consentements', 'branches'] as $t) {
        $cle = $t === 'noms_tokens' ? 'nom_id, token' : 'id';
        $res[$t] = Database::all("SELECT * FROM $t ORDER BY $cle");
    }
    $res['utilisateurs'] = Database::all('SELECT id, personne_id FROM utilisateurs ORDER BY id');
    return $res;
};

$idDoublon = static fn(): int => (int) Database::value('SELECT id FROM doublons_suspects WHERE personne_a_id = 14 AND personne_b_id = 15');

test('Recette 4 : ZOCLI / ZOKLI, même prénom, même père, années voisines signalés', function () use ($idDoublon) {
    Database::run("UPDATE liens_parente SET statut = 'declare' WHERE id = 15");
    $r = TacheService::lancer('detect_doublons');
    vrai($r['nouvelles'] >= 1, 'aucune paire');
    $id = $idDoublon();
    vrai($id > 0, 'paire 14/15 absente');
    vrai((int) Database::value('SELECT score FROM doublons_suspects WHERE id = ?', [$id]) >= 70, 'score');
    $liste = statut(api('GET', '/doublons', null, connecter('+2290100000013')), 200)['data'];
    vrai(in_array($id, array_column($liste, 'id'), true), 'absente de la liste');
    egal(0, TacheService::lancer('detect_doublons')['nouvelles'], 'pas recréée');
});

test('Doublons : poids du paramètre « prénom requis »', function () {
    Database::run("UPDATE parametres SET valeur = '1' WHERE cle = 'doublon_prenom_requis'");
    ParametreService::viderCache();
    Database::run("DELETE FROM doublons_suspects WHERE statut = 'a_traiter'");
    MatchingService::detecterDoublons();
    egal(0, (int) Database::value("SELECT COUNT(*) FROM doublons_suspects WHERE JSON_EXTRACT(detail_score, '$.prenom') IS NULL"));
    Database::run("UPDATE parametres SET valeur = '0' WHERE cle = 'doublon_prenom_requis'");
    ParametreService::viderCache();
    MatchingService::detecterDoublons();
});

test('Doublons : réservés aux référents, à leur branche, sans conflit', function () use ($idDoublon) {
    $id = $idDoublon();
    statut(api('GET', '/doublons', null, connecter('+2290100000016')), 403);
    statut(api('POST', "/doublons/$id/distinct", [], connecter('+2290100000016')), 403);
    statut(api('POST', "/doublons/$id/distinct", [], connecter('+2290100000010')), 403, 'autre branche');
});

test('Recette 5 : fusion puis annulation restituent exactement tout (ligne à ligne)', function () use ($photo, $idDoublon) {
    $id = $idDoublon();
    Database::run("INSERT INTO unions (epoux_id, epouse_id, rang_epouse, statut) VALUES (15, 13, 1, 'declare')");
    $autre = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 2]);
    Database::insert('liens_parente', ['parent_id' => 15, 'enfant_id' => $autre, 'role_parent' => 'pere', 'statut' => 'valide']);
    $avant = $photo();
    $f = statut(api('POST', "/doublons/$id/fusionner", ['personne_conservee_id' => 14], connecter('+2290100000013')), 200)['data']['fusion_id'];

    egal(14, (int) Database::value('SELECT fusionnee_dans_id FROM personnes WHERE id = 15'));
    egal(14, (int) Database::value('SELECT parent_id FROM liens_parente WHERE enfant_id = ?', [$autre]), 'lien transféré');
    egal(14, (int) Database::value('SELECT epoux_id FROM unions WHERE epouse_id = 13 ORDER BY id DESC LIMIT 1'));
    egal(0, (int) Database::value('SELECT COUNT(*) FROM noms_personne WHERE personne_id = 15'), 'noms transférés');
    egal(1, (int) Database::value("SELECT COUNT(*) FROM liens_parente WHERE enfant_id = 14 AND role_parent = 'pere' AND statut <> 'rejete'"));
    egal((int) Database::value('SELECT COUNT(*) FROM personnes'), count($avant['personnes']), 'rien de supprimé');
    egal(14, statut(api('GET', '/personnes/15', null, connecter('+2290100000018')), 200)['data']['id'], 'redirection');
    statut(api('POST', "/fusions/$f/annuler", [], connecter('+2290100000013')), 403, 'réservé à l\'admin');

    statut(api('POST', "/fusions/$f/annuler", [], jetonDirect(1)), 200);
    $apres = $photo();
    foreach ($avant as $table => $lignes) {
        egal($lignes, $apres[$table], "table $table");
    }
    egal('a_traiter', Database::value('SELECT statut FROM doublons_suspects WHERE id = ?', [$id]), 'paire à nouveau proposée');
    statut(api('POST', "/fusions/$f/annuler", [], jetonDirect(1)), 409, 'deuxième annulation');
});

test('Fusion : parents différents refusés, rien n\'est modifié', function () use ($photo) {
    $a = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 2]);
    $b = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 2]);
    Database::insert('liens_parente', ['parent_id' => 12, 'enfant_id' => $a, 'role_parent' => 'pere']);
    Database::insert('liens_parente', ['parent_id' => 8, 'enfant_id' => $b, 'role_parent' => 'pere']);
    $d = (int) Database::insert('doublons_suspects', ['personne_a_id' => $a, 'personne_b_id' => $b, 'score' => 80]);
    $avant = $photo();
    statut(api('POST', "/doublons/$d/fusionner", ['personne_conservee_id' => $a], connecter('+2290100000013')), 409);
    egal($avant['liens_parente'], $photo()['liens_parente']);
});

test('Fusion : plus récente d\'abord à l\'annulation', function () {
    $a = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 3]);
    $b = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 3]);
    $c = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 3]);
    $u = Database::one('SELECT * FROM utilisateurs WHERE id = 1');
    $f1 = \App\Services\FusionService::fusionner($u, $a, $b);
    $f2 = \App\Services\FusionService::fusionner($u, $a, $c);
    statut(api('POST', "/fusions/$f1/annuler", [], jetonDirect(1)), 409);
    statut(api('POST', "/fusions/$f2/annuler", [], jetonDirect(1)), 200);
    statut(api('POST', "/fusions/$f1/annuler", [], jetonDirect(1)), 200);
    egal(null, Database::value('SELECT fusionnee_dans_id FROM personnes WHERE id = ?', [$b]));
});

test('Une paire déclarée distincte n\'est plus jamais proposée', function () use ($idDoublon) {
    $id = $idDoublon();
    statut(api('POST', "/doublons/$id/distinct", [], connecter('+2290100000013')), 200);
    egal(1, (int) Database::value('SELECT COUNT(*) FROM paires_distinctes WHERE personne_a_id = 14 AND personne_b_id = 15'));
    Database::run('UPDATE fusions SET doublon_id = NULL WHERE doublon_id = ?', [$id]);
    Database::run('DELETE FROM doublons_suspects WHERE id = ?', [$id]);
    TacheService::lancer('detect_doublons');
    egal(0, (int) Database::value('SELECT COUNT(*) FROM doublons_suspects WHERE personne_a_id = 14 AND personne_b_id = 15'));
});

section('Lot 2 — journal');

test('Journal : UPDATE et DELETE refusés par la base', function () {
    foreach (['UPDATE journal_actions SET action = \'x\' LIMIT 1', 'DELETE FROM journal_actions LIMIT 1'] as $sql) {
        $refus = false;
        try {
            Database::run($sql);
        } catch (\PDOException $e) {
            $refus = str_contains($e->getMessage(), 'non modifiable');
        }
        vrai($refus, $sql);
    }
});

test('Journal : chaque type d\'action est tracé (qui, quoi, fiche, quand)', function () {
    foreach (['auth.connexion', 'personne.creation', 'consentement.accepte', 'union.declaration', 'lien.declaration', 'lien.validation', 'lien.rejet', 'lien.prive', 'lien.confirmation', 'fusion.effectuee', 'fusion.annulee', 'personne.consultation_privee', 'doublon.distinct', 'tache.execution'] as $a) {
        vrai((int) Database::value('SELECT COUNT(*) FROM journal_actions WHERE action = ?', [$a]) > 0, "action $a absente");
    }
    $l = Database::one("SELECT * FROM journal_actions WHERE action = 'lien.validation' LIMIT 1");
    vrai($l['utilisateur_id'] !== null && $l['entite_id'] !== null && $l['cree_le'] !== null, 'champs');
});
