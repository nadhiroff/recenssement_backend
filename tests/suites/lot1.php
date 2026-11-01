<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\MatchingService;
use App\Services\NormalisationService as N;
use App\Services\ParametreService;

section('Lot 1 — unitaires');

test('Normalisation : accents, majuscules, ponctuation', function () {
    egal('SENAMI', N::normaliser('Sènami', []));
    egal('JEAN MARIE', N::normaliser("  jean-marie  ", []));
    egal('BENOIT', N::normaliser('Benoît', []));
    egal('DOVI', N::normaliser('Ɖovi', []));
    egal('O NEIL', N::normaliser("O'Neil", []));
});

test('Normalisation : ZOKLI et ZOCKLI ramenés à ZOCLI', function () {
    egal('ZOCLI', N::normaliser('Zokli'));
    egal('ZOCLI', N::normaliser('ZOCKLI'));
    egal('ZOCLI', N::normaliser('zocli'));
});

test('Normalisation : variante ajoutée sans redéploiement, recalcul', function () {
    Database::run("INSERT INTO variantes_noms (variante, forme_canonique) VALUES ('ZOQLI', 'ZOCLI')");
    $id = (int) Database::insert('noms_personne', ['personne_id' => 15, 'type' => 'surnom', 'valeur' => 'Zoqli', 'valeur_normalisee' => 'ZOQLI', 'principal' => 0]);
    N::recalculerTout();
    egal('ZOCLI', Database::value('SELECT valeur_normalisee FROM noms_personne WHERE id = ?', [$id]));
    Database::run('DELETE FROM noms_personne WHERE id = ?', [$id]);
    Database::run("DELETE FROM variantes_noms WHERE variante = 'ZOQLI'");
    N::viderCache();
});

test('Score : chaque critère vaut son poids', function () {
    $p = ['prenom' => 30, 'branche' => 20, 'pere' => 25, 'naissance' => 15, 'mere' => 10, 'ecart' => 5];
    $a = ['tokens' => ['KOSSI'], 'branche_id' => 1, 'annee' => 1968, 'pere_id' => 5, 'mere_id' => 6];
    egal(100, MatchingService::scorer($a, $a, $p)['score']);
    $b = ['tokens' => ['AUTRE'], 'branche_id' => 2, 'annee' => 1974, 'pere_id' => 7, 'mere_id' => 8];
    egal(0, MatchingService::scorer($a, $b, $p)['score']);
    egal(15, MatchingService::scorer($a, ['annee' => 1973, 'tokens' => []], $p)['score']);
});

test('Score : poids modifiés en base, sans redéploiement', function () {
    $avant = MatchingService::poids();
    egal(30, $avant['prenom']);
    Database::run("UPDATE parametres SET valeur = '45' WHERE cle = 'score_prenom_commun'");
    ParametreService::viderCache();
    $a = ['tokens' => ['X']];
    egal(45, MatchingService::scorer($a, $a, MatchingService::poids())['score']);
    Database::run("UPDATE parametres SET valeur = '30' WHERE cle = 'score_prenom_commun'");
    ParametreService::viderCache();
});

test('Téléphone : format béninois +22901, ancien numéro converti, autres pays acceptés', function () {
    $v = static fn(string $t): ?string => \App\Core\Validator::telephone(['t' => $t], 't');
    egal('+2290197123456', $v('+229 01 97 12 34 56'));
    egal('+2290197123456', $v('+22997123456'), 'ancien format');
    egal('+22890123456', $v('+228 90 12 34 56'));
    foreach (['+2290297123456', '+229971234', '22997123456'] as $mauvais) {
        $refus = false;
        try {
            $v($mauvais);
        } catch (\App\Core\ApiException) {
            $refus = true;
        }
        vrai($refus, $mauvais);
    }
});

section('Lot 1 — authentification');

test('OTP : code haché, connexion, /me', function () {
    $t = '+2290161000001';
    statut(api('POST', '/auth/otp/request', ['telephone' => $t, 'email' => 'nouveau1@example.test']), 200);
    $code = dernierOtp($t);
    $ligne = Database::one('SELECT * FROM otp_codes WHERE identifiant = ? ORDER BY id DESC LIMIT 1', [$t]);
    vrai($ligne['code_hash'] !== $code && password_verify($code, $ligne['code_hash']), 'code non haché');
    $r = statut(api('POST', '/auth/otp/verify', ['telephone' => $t, 'code' => $code]), 200);
    $moi = statut(api('GET', '/me', null, $r['data']['token']), 200);
    egal('membre', $moi['data']['role']);
    egal(null, $moi['data']['personne']);
    vrai(Database::value('SELECT COUNT(*) FROM sessions_api WHERE token_hash = ?', [hash('sha256', $r['data']['token'])]) == 1, 'jeton non haché');
});

test('OTP : premier accès sans email refusé', function () {
    egal('email_requis', statut(api('POST', '/auth/otp/request', ['telephone' => '+2290161000002']), 422)['error']['code']);
});

test('OTP : 5 tentatives maximum, code réutilisé refusé, expiration', function () {
    $t = '+2290161000003';
    api('POST', '/auth/otp/request', ['telephone' => $t, 'email' => 'n3@example.test']);
    for ($i = 0; $i < 5; $i++) {
        statut(api('POST', '/auth/otp/verify', ['telephone' => $t, 'code' => '000000']), 401);
    }
    statut(api('POST', '/auth/otp/verify', ['telephone' => $t, 'code' => dernierOtp($t)]), 429);
    api('POST', '/auth/otp/request', ['telephone' => $t, 'email' => 'n3@example.test']);
    $code = dernierOtp($t);
    Database::run('UPDATE otp_codes SET expire_le = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE identifiant = ?', [$t]);
    statut(api('POST', '/auth/otp/verify', ['telephone' => $t, 'code' => $code]), 401, 'expiré');
    api('POST', '/auth/otp/request', ['telephone' => $t, 'email' => 'n3@example.test']);
    statut(api('POST', '/auth/otp/verify', ['telephone' => $t, 'code' => dernierOtp($t)]), 200);
    statut(api('POST', '/auth/otp/verify', ['telephone' => $t, 'code' => dernierOtp($t)]), 401, 'réutilisé');
});

test('OTP : limitation de débit par numéro', function () {
    $t = '+2290161000004';
    for ($i = 0; $i < 5; $i++) {
        statut(api('POST', '/auth/otp/request', ['telephone' => $t, 'email' => 'n4@example.test']), 200);
    }
    statut(api('POST', '/auth/otp/request', ['telephone' => $t, 'email' => 'n4@example.test']), 429);
});

test('OTP : un compte existant ne peut pas être détourné vers un autre email', function () {
    statut(api('POST', '/auth/otp/request', ['telephone' => '+2290100000016', 'email' => 'pirate@example.test']), 200);
    egal(null, Database::value("SELECT email_destination FROM otp_codes WHERE identifiant = '+2290100000016' ORDER BY id DESC LIMIT 1"));
    $mail = file(BASE_PATH . '/storage/logs/mail.log', FILE_IGNORE_NEW_LINES);
    vrai(str_contains(end($mail), substr(hash('sha256', 'edem.demo@example.test'), 0, 8)), 'code envoyé ailleurs');
});

test('Numéro de téléphone absent des journaux techniques', function () {
    foreach (['otp.log', 'mail.log'] as $f) {
        vrai(!str_contains((string) file_get_contents(BASE_PATH . "/storage/logs/$f"), '+229'), "numéro dans $f");
    }
});

test('Déconnexion : jeton révoqué ; sans jeton 401', function () {
    $j = connecter('+2290161000005', 'n5@example.test');
    statut(api('POST', '/auth/logout', [], $j), 200);
    statut(api('GET', '/me', null, $j), 401);
    statut(api('GET', '/me'), 401);
});

test('Routage de repli ?route= quand la réécriture est absente', function () {
    statut(api('GET', '/me', null, null, true), 401);
    $j = connecter('+2290161000005');
    vrai(count(statut(api('GET', '/branches', null, $j, true), 200)['data']) === 3, 'branches');
    statut(api('GET', '/inexistant', null, $j), 404);
});

section('Lot 1 — inscription');

$inscription = static fn(array $extra = []): array => $extra + [
    'client_uuid' => uuid(),
    'identite' => identiteTest('Comlanvi'),
    'branche_id' => 1,
    'pere' => ['personne_id' => 9],
    'mere' => ['nouvelle' => ['nom_famille' => 'AHO', 'prenoms' => 'Nadège', 'annee_naissance' => 1970, 'rapprochement_vu' => true]],
    'consentement' => true,
];

test('Recette 1 : inscription complète, rejeu même client_uuid sans doublon', function () use ($inscription) {
    $j = connecter('+2290161000006', 'n6@example.test');
    $corps = $inscription();
    $avant = (int) Database::value('SELECT COUNT(*) FROM personnes');
    $r = statut(api('POST', '/inscriptions', $corps, $j), 201);
    $id = $r['data']['personne_id'];
    egal($avant + 2, (int) Database::value('SELECT COUNT(*) FROM personnes'), 'personne + mère');
    egal(2, (int) Database::value("SELECT COUNT(*) FROM liens_parente WHERE enfant_id = ? AND statut = 'declare'", [$id]));
    vrai(Database::value('SELECT accepte_le FROM consentements WHERE personne_id = ?', [$id]) !== null, 'consentement');
    egal('+2290161000006', Database::value('SELECT telephone_whatsapp FROM personnes WHERE id = ?', [$id]));

    $r2 = statut(api('POST', '/inscriptions', $corps, $j), 200, 'rejeu');
    egal(true, $r2['data']['rejoue']);
    egal($id, $r2['data']['personne_id']);
    egal($avant + 2, (int) Database::value('SELECT COUNT(*) FROM personnes'), 'rejeu sans doublon');
    egal(2, (int) Database::value('SELECT COUNT(*) FROM liens_parente WHERE enfant_id = ?', [$id]));
    statut(api('POST', '/inscriptions', $inscription(), $j), 409, 'deuxième fiche');
});

test('Envois simultanés du même client_uuid : une seule fiche', function () use ($inscription) {
    $j = connecter('+2290161000020', 'n20@example.test');
    $corps = $inscription(['identite' => identiteTest('Simultané')]);
    $mh = curl_multi_init();
    $hs = [];
    for ($i = 0; $i < 4; $i++) {
        $c = curl_init('http://127.0.0.1:' . TEST_PORT . '/api/index.php/v1/inscriptions');
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($corps), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $j"]]);
        curl_multi_add_handle($mh, $c);
        $hs[] = $c;
    }
    do {
        curl_multi_exec($mh, $actif);
        curl_multi_select($mh, 0.05);
    } while ($actif);
    egal(1, (int) Database::value("SELECT COUNT(*) FROM noms_personne WHERE valeur = 'Simultané'"));
});

test('Inscription : sans consentement refusée, rien de créé', function () use ($inscription) {
    $j = connecter('+2290161000007', 'n7@example.test');
    $avant = (int) Database::value('SELECT COUNT(*) FROM personnes');
    egal('consentement', statut(api('POST', '/inscriptions', $inscription(['consentement' => false]), $j), 422)['error']['champ']);
    egal($avant, (int) Database::value('SELECT COUNT(*) FROM personnes'));
});

test('Inscription : fiche ressemblante non vue → 409 avec candidats ; annulation complète', function () use ($inscription) {
    $j = connecter('+2290161000008', 'n8@example.test');
    $avant = (int) Database::value('SELECT COUNT(*) FROM personnes');
    $corps = $inscription(['pere' => ['nouvelle' => ['nom_famille' => 'ZOCLI', 'prenoms' => 'Kossi', 'annee_naissance' => 1968, 'pere_nom' => 'Mawuli']]]);
    $r = statut(api('POST', '/inscriptions', $corps, $j), 409);
    egal('rapprochement_requis', $r['error']['code']);
    egal(9, $r['error']['candidats'][0]['personne']['id']);
    egal($avant, (int) Database::value('SELECT COUNT(*) FROM personnes'), 'transaction annulée');
    $corps['pere']['nouvelle']['rapprochement_vu'] = true;
    statut(api('POST', '/inscriptions', $corps, $j), 201);
});

test('Inscription : deux noms supplémentaires servent à la recherche', function () use ($inscription) {
    $j = connecter('+2290161000009', 'n9@example.test');
    $id = statut(api('POST', '/inscriptions', $inscription([
        'identite' => identiteTest('Rodrigue', ['autres_noms' => [['type' => 'vodoun', 'valeur' => 'Dansou'], ['type' => 'surnom', 'valeur' => 'Le Grand']]]),
    ]), $j), 201)['data']['personne_id'];
    $r = statut(api('GET', '/personnes/recherche?q=dansou', null, $j), 200);
    egal([$id], array_column($r['data'], 'id'));
    egal([$id], array_column(api('GET', '/personnes/recherche?q=grand', null, $j)['data'], 'id'));
});

test('Inscription : année approximative et date absente acceptées', function () use ($inscription) {
    $j = connecter('+2290161000010', 'n10@example.test');
    $id = statut(api('POST', '/inscriptions', $inscription(['identite' => ['nom_famille' => 'ZOCLI', 'prenoms' => 'Sans Date', 'sexe' => 'F']]), $j), 201)['data']['personne_id'];
    egal('inconnue', Database::value('SELECT precision_naissance FROM personnes WHERE id = ?', [$id]));
    $j2 = connecter('+2290161000011', 'n11@example.test');
    statut(api('POST', '/inscriptions', $inscription(['identite' => identiteTest('Futur', ['annee_naissance' => 2999])]), $j2), 422);
});

test('Lien : un seul père actif par enfant (contrainte en base)', function () {
    $erreur = false;
    try {
        Database::run("INSERT INTO liens_parente (parent_id, enfant_id, role_parent) VALUES (2, 9, 'pere')");
    } catch (\PDOException $e) {
        $erreur = str_contains($e->getMessage(), 'uq_lien_actif');
    }
    vrai($erreur, 'contrainte absente');
});

section('Lot 1 — rapprochement');

test('Recette 2 : « Kossi », né vers 1968, bon grand-père → fiche proposée', function () {
    $j = connecter('+2290161000012', 'n12@example.test');
    $r = statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'Kossi', 'annee_naissance' => 1968, 'pere_id' => 5, 'branche_id' => 1], $j), 200);
    egal(9, $r['data'][0]['personne']['id']);
    vrai($r['data'][0]['score'] >= 50, 'score');
    // même chose quand le grand-père n'est saisi que par son nom, sans branche
    $r = statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'kossi', 'annee_naissance' => 1968, 'pere_nom' => 'Mawuli'], $j), 200);
    egal(9, $r['data'][0]['personne']['id']);
    egal('Mawuli ZOCLI', $r['data'][0]['filiation']);
    vrai(!isset($r['data'][0]['personne']['telephone_whatsapp']), 'téléphone exposé');
});

test('Rapprochement : sous le seuil, aucune proposition', function () {
    $j = connecter('+2290161000012');
    egal([], statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'Kossi', 'annee_naissance' => 1900], $j), 200)['data']);
});

test('Rapprochement : prénom retrouvé via un nom secondaire (vodoun)', function () {
    $j = connecter('+2290161000012');
    $r = statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'Hounnon', 'annee_naissance' => 1970, 'branche_id' => 1], $j), 200);
    egal(9, $r['data'][0]['personne']['id']);
});

test('Rapprochement : fiche privée anonymisée pour un membre', function () {
    $j = connecter('+2290161000012');
    $r = statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'Zinsou', 'annee_naissance' => 1990, 'branche_id' => 1], $j), 200);
    egal([['anonyme' => true]], $r['data']);
});

test('Rapprochement : limité en débit (anti-aspiration)', function () {
    $j = connecter('+2290161000012');
    [$max] = RATE_RECHERCHE;
    Database::run('INSERT INTO rate_limits (cle, fenetre_debut, compteur) VALUES (?, ?, ?)', [hash('sha256', 'recherche|u' . Database::value("SELECT id FROM utilisateurs WHERE telephone_whatsapp = '+2290161000012'")), time(), $max]);
    statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'Kossi'], $j), 429);
});

test('Recette 3 : un père à deux épouses les fait apparaître toutes les deux', function () {
    $j = connecter('+2290161000012');
    $r = statut(api('GET', '/personnes/5/epouses', null, $j), 200);
    egal([6, 7], array_map(static fn(array $e): int => $e['personne']['id'], $r['data']));
    egal([1, 2], array_column($r['data'], 'rang_epouse'));
    vrai(!str_contains($r['brut'], '+229'), 'téléphone exposé');
});

test('Union : créée à l\'inscription, rang suivant attribué', function () use ($inscription) {
    $j = connecter('+2290161000013', 'n13@example.test');
    $r = statut(api('POST', '/inscriptions', $inscription(['identite' => identiteTest('Rang')]), $j), 201);
    $rang = (int) Database::value('SELECT rang_epouse FROM unions WHERE epoux_id = 9 AND epouse_id = ?', [$r['data']['mere_id']]);
    vrai($rang >= 1, 'union absente');
    $id = statut(api('POST', '/unions', ['epoux_id' => 9, 'epouse_id' => 10], $j), 201)['data']['union_id'];
    egal($rang + 1, (int) Database::value('SELECT rang_epouse FROM unions WHERE id = ?', [$id]));
});

section('Lot 1 — tiers');

$tiers = static fn(array $extra): array => $extra + ['client_uuid' => uuid(), 'branche_id' => 1, 'consentement' => true];

test('Tiers : enfant mineur rattaché au déclarant, trace conservée', function () use ($tiers) {
    $j = connecter('+2290100000016');
    $r = statut(api('POST', '/inscriptions/tiers', $tiers(['type' => 'enfant_mineur', 'identite' => identiteTest('Petit', ['annee_naissance' => 2020])]), $j), 201);
    egal(16, (int) Database::value("SELECT parent_id FROM liens_parente WHERE enfant_id = ? AND role_parent = 'pere'", [$r['data']['personne_id']]));
    egal(9, (int) Database::value('SELECT declarant_utilisateur_id FROM declarations_tiers WHERE personne_id = ?', [$r['data']['personne_id']]));
    statut(api('POST', '/inscriptions/tiers', $tiers(['type' => 'enfant_mineur', 'identite' => identiteTest('Vieux', ['annee_naissance' => 1980])]), $j), 422);
});

test('Tiers : parent décédé marqué décédé et relié au déclarant', function () use ($tiers) {
    $j = connecter('+2290161000014', 'n14@example.test');
    $moi = statut(api('POST', '/inscriptions', ['client_uuid' => uuid(), 'identite' => identiteTest('Orphelin'), 'branche_id' => 3, 'consentement' => true], $j), 201)['data']['personne_id'];
    $r = statut(api('POST', '/inscriptions/tiers', $tiers(['type' => 'parent_decede', 'branche_id' => 3,
        'identite' => identiteTest('Défunt', ['annee_naissance' => 1950, 'rapprochement_vu' => true])]), $j), 201);
    egal(1, (int) Database::value('SELECT est_decede FROM personnes WHERE id = ?', [$r['data']['personne_id']]));
    egal($r['data']['personne_id'], (int) Database::value("SELECT parent_id FROM liens_parente WHERE enfant_id = ? AND role_parent = 'pere'", [$moi]));
});

test('Tiers : aîné réservé au relais de la branche, présence obligatoire', function () use ($tiers) {
    $corps = fn(array $e = []): array => $tiers($e + ['type' => 'aine', 'present_lors_saisie' => true, 'identite' => identiteTest('Aîné', ['annee_naissance' => 1940, 'rapprochement_vu' => true])]);
    statut(api('POST', '/inscriptions/tiers', $corps(), connecter('+2290100000016')), 403, 'membre');
    $relais = connecter('+2290100000011');
    statut(api('POST', '/inscriptions/tiers', $corps(['present_lors_saisie' => false]), $relais), 422, 'absent');
    statut(api('POST', '/inscriptions/tiers', $corps(['branche_id' => 2]), $relais), 403, 'autre branche');
    $r = statut(api('POST', '/inscriptions/tiers', $corps(), $relais), 201);
    egal(1, (int) Database::value('SELECT present_lors_saisie FROM declarations_tiers WHERE personne_id = ?', [$r['data']['personne_id']]));
});

section('Lot 1 — fiches');

test('Fiche : le membre corrige la sienne, pas celle d\'un autre', function () {
    $j = connecter('+2290100000017');
    statut(api('PATCH', '/personnes/17', ['lieu_residence' => 'Bordeaux'], $j), 200);
    egal('Bordeaux', Database::value('SELECT lieu_residence FROM personnes WHERE id = 17'));
    statut(api('PATCH', '/personnes/16', ['lieu_residence' => 'Bordeaux'], $j), 403);
    statut(api('PATCH', '/personnes/17', ['telephone_whatsapp' => '+2290100000999', 'est_fondateur' => true], $j), 200);
    egal('+2290100000017', Database::value('SELECT telephone_whatsapp FROM personnes WHERE id = 17'), 'champ non autorisé ignoré');
});

test('Fiche : retrait masque la fiche, révoque la session, garde le journal', function () {
    $j = connecter('+2290161000015', 'n15@example.test');
    $id = statut(api('POST', '/inscriptions', ['client_uuid' => uuid(), 'identite' => identiteTest('Retrait'), 'branche_id' => 1, 'consentement' => true], $j), 201)['data']['personne_id'];
    statut(api('POST', "/personnes/$id/retrait", [], $j), 422, 'sans confirmation');
    statut(api('POST', "/personnes/$id/retrait", ['confirmation' => true], $j), 200);
    statut(api('GET', '/me', null, $j), 401);
    statut(api('GET', "/personnes/$id", null, connecter('+2290100000016')), 404);
    egal(null, Database::value('SELECT telephone_whatsapp FROM personnes WHERE id = ?', [$id]));
    vrai(Database::value('SELECT retire_le FROM consentements WHERE personne_id = ?', [$id]) !== null, 'consentement');
    vrai((int) Database::value("SELECT COUNT(*) FROM journal_actions WHERE action = 'personne.retrait' AND entite_id = ?", [$id]) === 1, 'journal');
});

test('Fiche : le référent de la branche voit le téléphone, celui d\'une autre non', function () {
    egal('+2290100000017', statut(api('GET', '/personnes/17', null, connecter('+2290100000010')), 200)['data']['telephone_whatsapp']);
    vrai(!isset(statut(api('GET', '/personnes/17', null, connecter('+2290100000012')), 200)['data']['telephone_whatsapp']), 'autre branche');
});

test('Fiche privée : référent journalisé, membre 404', function () {
    statut(api('GET', '/personnes/19', null, connecter('+2290100000016')), 404);
    statut(api('GET', '/personnes/19', null, connecter('+2290100000010')), 200);
    vrai((int) Database::value("SELECT COUNT(*) FROM journal_actions WHERE action = 'personne.consultation_privee' AND entite_id = 19") >= 1, 'non journalisé');
});
