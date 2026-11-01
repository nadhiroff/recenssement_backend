<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Router;
use App\Services\BackupService;
use App\Services\CsvExporter;
use App\Services\GedcomExporter;
use App\Services\ParametreService;
use App\Services\TacheService;

section('Lot 3 — arbre');

$nomsFoyers = static fn(array $n): array => array_map(
    static fn(array $f): array => [$f['conjoint']['nom_complet'] ?? null, array_map(static fn(array $e): int => $e['personne']['id'], $f['enfants'])],
    $n['foyers']
);

test('Polygamie : enfants d\'un père regroupés par mère, dans l\'ordre du rang', function () use ($nomsFoyers) {
    $n = statut(api('GET', '/arbre?racine=5&profondeur=1', null, connecter('+2290100000020')), 200)['data'];
    egal([['Afi ADJOVI', [9, 10]], ['Akouvi HOUNSOU', [11]]], $nomsFoyers($n));
});

test('Arbre membre : liens validés seulement, sans téléphone ni fiche privée', function () {
    $j = connecter('+2290100000020');
    $r = statut(api('GET', '/arbre?racine=11&profondeur=2', null, $j), 200);
    vrai(!str_contains($r['brut'], 'Zinsou'), 'fiche privée exposée');
    vrai(!preg_match('/\+\d{8,}/', $r['brut']), 'téléphone exposé');
    vrai(!str_contains($r['brut'], '"id":21'), 'lien confirmé visible');
    $r = statut(api('GET', '/arbre?racine=5&profondeur=2', null, $j), 200);
    $edem = 0;
    foreach ($r['data']['foyers'] as $f) {
        foreach ($f['enfants'] as $e) {
            foreach ($e['foyers'] ?? [] as $g) {
                foreach ($g['enfants'] as $x) {
                    $edem += (int) ($x['personne']['id'] === 17);
                }
            }
        }
    }
    egal(1, $edem, 'petite-fille validée');
});

test('Arbre référent : provisoires visibles et distingués, privés de sa branche inclus', function () {
    $r = statut(api('GET', '/arbre?racine=11&profondeur=1', null, connecter('+2290100000010')), 200);
    $statuts = [];
    foreach ($r['data']['foyers'] as $f) {
        foreach ($f['enfants'] as $e) {
            $statuts[$e['personne']['id']] = $e['statut_lien'];
        }
    }
    egal('prive', $statuts[19]);
    egal('confirme', $statuts[21]);
});

test('Arbre référent d\'une autre branche : voit les provisoires, pas les situations privées', function () {
    $r = statut(api('GET', '/arbre?racine=11&profondeur=1', null, connecter('+2290100000012')), 200);
    $ids = [];
    foreach ($r['data']['foyers'] as $f) {
        $ids = [...$ids, ...array_map(static fn(array $e): int => $e['personne']['id'], $f['enfants'])];
    }
    vrai(in_array(21, $ids, true), 'lien provisoire absent');
    vrai(!in_array(19, $ids, true) && !str_contains($r['brut'], 'Zinsou'), 'situation privée exposée');
    vrai(!preg_match('/\+\d{8,}/', $r['brut']), 'téléphone d\'une autre branche');
});

test('Arbre : charge par niveaux (profondeur bornée, a_suite signalé)', function () {
    $j = connecter('+2290100000020');
    $n = statut(api('GET', '/arbre?racine=2&profondeur=1', null, $j), 200)['data'];
    $enfant = $n['foyers'][0]['enfants'][0];
    egal(5, $enfant['personne']['id']);
    egal(true, $enfant['a_suite']);
    vrai(!isset($enfant['foyers']), 'niveau suivant chargé à tort');
    statut(api('GET', '/arbre?branche=1', null, $j), 200);
    egal(1, statut(api('GET', '/arbre', null, $j), 200)['data']['personne']['id'], 'fondateur');
});

test('Paramètre arbre_visible_membres : membre refusé, référent autorisé', function () {
    Database::run("UPDATE parametres SET valeur = '0' WHERE cle = 'arbre_visible_membres'");
    statut(api('GET', '/arbre', null, connecter('+2290100000020')), 403);
    statut(api('GET', '/arbre', null, connecter('+2290100000010')), 200);
    Database::run("UPDATE parametres SET valeur = '1' WHERE cle = 'arbre_visible_membres'");
});

test('Paramètre generations_lignee_feminine : limite la descendance par les filles', function () {
    $e = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 3]);
    Database::insert('liens_parente', ['parent_id' => 4, 'enfant_id' => $e, 'role_parent' => 'mere', 'statut' => 'valide']);
    Database::run("UPDATE parametres SET valeur = '0' WHERE cle = 'generations_lignee_feminine'");
    $j = connecter('+2290100000020');
    egal([], statut(api('GET', '/arbre?racine=4&profondeur=1', null, $j), 200)['data']['foyers']);
    Database::run("UPDATE parametres SET valeur = '-1' WHERE cle = 'generations_lignee_feminine'");
    vrai(statut(api('GET', '/arbre?racine=4&profondeur=1', null, $j), 200)['data']['foyers'] !== [], 'sans limite');
});

test('Fiche famille : mêmes règles que l\'arbre, selon le rôle', function () {
    $m = statut(api('GET', '/personnes/11/famille', null, connecter('+2290100000020')), 200);
    egal([5, 7], array_map(static fn(array $p): int => $p['personne']['id'], $m['data']['parents']));
    vrai(!str_contains($m['brut'], 'Zinsou') && !preg_match('/\+\d{8,}/', $m['brut']), 'fiche privée ou téléphone exposé');
    vrai(!in_array(21, array_map(static fn(array $p): int => $p['personne']['id'], $m['data']['enfants']), true), 'lien non validé visible');
    $r = statut(api('GET', '/personnes/11/famille', null, connecter('+2290100000010')), 200)['data'];
    vrai(in_array(19, array_map(static fn(array $p): int => $p['personne']['id'], $r['enfants']), true), 'référent : tout');
    egal([6, 7], array_map(static fn(array $p): int => $p['personne']['id'], statut(api('GET', '/personnes/5/famille', null, connecter('+2290100000020')), 200)['data']['conjoints']));
});

test('Fiche famille : ses propres liens en cours visibles, pas ceux des autres', function () {
    $j = connecter('+2290100000021', 'kodjo.test@example.test');
    $u = (int) Database::value("SELECT id FROM utilisateurs WHERE telephone_whatsapp = '+2290100000021'");
    Database::run('UPDATE utilisateurs SET personne_id = 21 WHERE id = ?', [$u]);
    $moi = statut(api('GET', '/personnes/21/famille', null, $j), 200)['data']['parents'];
    egal('confirme', $moi[0]['statut'], 'lien confirmé sur sa fiche');
    egal([], statut(api('GET', '/personnes/21/famille', null, connecter('+2290100000020')), 200)['data']['parents'], 'autre membre');
    Database::run('UPDATE utilisateurs SET personne_id = NULL WHERE id = ?', [$u]);
});

test('Ordre des épouses : référent de la branche, sans conflit, l\'arbre suit', function () use ($nomsFoyers) {
    $pere = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 2]);
    \App\Repositories\PersonneRepository::ajouterNoms($pere, [['type' => 'naissance', 'valeur' => 'Foyer']]);
    $e1 = (int) Database::insert('personnes', ['sexe' => 'F', 'branche_id' => 2]);
    $e2 = (int) Database::insert('personnes', ['sexe' => 'F', 'branche_id' => 2]);
    \App\Repositories\PersonneRepository::ajouterNoms($e1, [['type' => 'naissance', 'valeur' => 'Premiere']]);
    \App\Repositories\PersonneRepository::ajouterNoms($e2, [['type' => 'naissance', 'valeur' => 'Seconde']]);
    $u1 = (int) Database::insert('unions', ['epoux_id' => $pere, 'epouse_id' => $e1, 'rang_epouse' => 1, 'statut' => 'valide']);
    $u2 = (int) Database::insert('unions', ['epoux_id' => $pere, 'epouse_id' => $e2, 'rang_epouse' => 2, 'statut' => 'valide']);
    $url = "/personnes/$pere/epouses/ordre";
    statut(api('POST', $url, ['ordre' => [$u2, $u1]], connecter('+2290100000020')), 403, 'membre');
    statut(api('POST', $url, ['ordre' => [$u2, $u1]], connecter('+2290100000010')), 403, 'autre branche');
    $j = connecter('+2290100000013');
    statut(api('POST', $url, ['ordre' => [$u2, 999999]], $j), 422, 'union étrangère');
    statut(api('POST', $url, ['ordre' => [$u2, $u2]], $j), 422, 'doublon');
    statut(api('POST', $url, ['ordre' => [$u2, $u1]], $j), 200);
    egal(1, (int) Database::value('SELECT rang_epouse FROM unions WHERE id = ?', [$u2]));
    egal(2, (int) Database::value('SELECT rang_epouse FROM unions WHERE id = ?', [$u1]));
    egal([$u2, $u1], array_column(statut(api('GET', "/personnes/$pere/epouses", null, $j), 200)['data'], 'union_id'));
    egal(['Seconde', 'Premiere'], array_map(static fn(array $f): string => trim((string) $f[0]), $nomsFoyers(statut(api('GET', "/arbre?racine=$pere&profondeur=1", null, connecter('+2290100000020')), 200)['data'])));
    egal(1, (int) Database::value("SELECT COUNT(*) FROM journal_actions WHERE action = 'union.rang' AND entite_id = ?", [$pere]));
    // Kossi et Enyonam sont enfants de Mawuli : ni l'un ni l'autre ne touche à son foyer
    statut(api('POST', '/personnes/5/epouses/ordre', ['ordre' => [2, 1]], connecter('+2290100000009')), 403, 'conflit');
});

section('Lot 3 — suivi');

test('Stats : par branche et par statut, limitées à la branche du référent', function () {
    $s = statut(api('GET', '/stats', null, connecter('+2290100000010')), 200)['data'];
    egal([1], array_column($s, 'branche_id'));
    vrai($s[0]['liens']['valide'] > 0 && $s[0]['personnes'] > 0, 'compteurs');
    egal(3, count(statut(api('GET', '/stats', null, jetonDirect(1)), 200)['data']));
    statut(api('GET', '/stats', null, connecter('+2290100000020')), 403);
});

test('Journal : référent limité à sa branche, admin complet, membre refusé', function () {
    statut(api('GET', '/journal', null, connecter('+2290100000020')), 403);
    $r = statut(api('GET', '/journal?per_page=100', null, connecter('+2290100000010')), 200)['data'];
    vrai($r !== [] && array_unique(array_column($r, 'branche_id')) === [1], 'portée');
    vrai(count(statut(api('GET', '/journal?per_page=100', null, jetonDirect(1)), 200)['data']) >= count($r), 'admin');
});

section('Lot 3 — exports');

test('GEDCOM : en-tête, dates approximatives, polygamie, TRLR', function () {
    $g = GedcomExporter::generer();
    vrai(str_starts_with($g, "0 HEAD\r\n"), 'HEAD');
    vrai(str_ends_with($g, "0 TRLR\r\n"), 'TRLR');
    foreach (['2 VERS 5.5.1', '1 CHAR UTF-8', '2 FORM LINEAGE-LINKED', '1 NAME Kossi /ZOCLI/', '2 DATE ABT 1968', "2 DATE 1972\r\n", '2 DATE BET 1880 AND 1889', "2 TYPE vodoun"] as $att) {
        vrai(str_contains($g, $att), "absent : $att");
    }
    egal(2, preg_match_all('/1 HUSB @I5@/', $g), 'un FAM par couple');
    vrai(!preg_match('/\+\d{8,}/', $g), 'téléphone dans le GEDCOM');
});

test('Recette 8 (partie) : GEDCOM limité aux liens validés', function () {
    $g = GedcomExporter::generer();
    vrai(!str_contains($g, 'Zinsou'), 'fiche privée');
    // Chaque paire CHIL/parent doit correspondre à un lien validé
    preg_match_all('/0 @F\d+@ FAM\r\n(.*?)(?=0 @|\z)/s', $g, $m);
    foreach ($m[1] as $bloc) {
        preg_match('/HUSB @I(\d+)@/', $bloc, $h);
        preg_match('/WIFE @I(\d+)@/', $bloc, $w);
        preg_match_all('/CHIL @I(\d+)@/', $bloc, $c);
        foreach ($c[1] as $enfant) {
            foreach (array_filter([$h[1] ?? null, $w[1] ?? null]) as $parent) {
                egal('valide', Database::value("SELECT statut FROM liens_parente WHERE parent_id = ? AND enfant_id = ? AND statut <> 'rejete'", [$parent, $enfant]), "$parent → $enfant");
            }
        }
    }
    $declare = Database::one("SELECT enfant_id FROM liens_parente WHERE statut = 'en_examen' AND enfant_id = 20");
    vrai($declare !== null && !str_contains($g, 'Mawuena'), 'fiche sans lien validé');
});

test('Recette 8 : le fichier GEDCOM s\'ouvre dans Gramps sans erreur', function () {
    $dossier = BASE_PATH . '/storage/exports';
    file_put_contents("$dossier/recette.ged", GedcomExporter::generer());
    chmod("$dossier/recette.ged", 0644);
    exec('docker image inspect zocli-gramps:local >/dev/null 2>&1', $o, $code);
    if ($code !== 0) {
        throw new \RuntimeException('Gramps indisponible : image Docker zocli-gramps:local absente');
    }
    $cmd = sprintf(
        'docker run --rm -v %s:/d zocli-gramps:local bash -c %s 2>&1',
        escapeshellarg($dossier),
        escapeshellarg('cd /tmp && gramps -y -C recette -i /d/recette.ged -e /tmp/sortie.csv -f csv && test -s /tmp/sortie.csv && grep -c ZOCLI /tmp/sortie.csv && grep -iE "error|erreur|invalid|unknown" /tmp/sortie.csv || true')
    );
    exec($cmd, $sortie, $code);
    $texte = implode("\n", $sortie);
    file_put_contents(BASE_PATH . '/storage/logs/gramps.log', $texte);
    egal(0, $code, $texte);
    vrai(str_contains($texte, 'GEDCOM import report: No errors detected'), $texte);
    vrai(!str_contains($texte, 'Traceback'), $texte);
    vrai(preg_match('/^\d+$/m', $texte) === 1, "export vide : $texte");
});

test('GEDCOM : date complète seulement pour une personne décédée', function () {
    Database::run('UPDATE personnes SET mois_naissance = 3, jour_naissance = 12 WHERE id IN (2, 9)');
    Database::run("UPDATE personnes SET precision_naissance = 'exacte' WHERE id IN (2, 9)");
    $g = GedcomExporter::generer();
    vrai(str_contains($g, '2 DATE 12 MAR 1910'), 'décédé');
    vrai(str_contains($g, "2 DATE 1968\r\n") && !str_contains($g, '12 MAR 1968'), 'vivant');
    Database::run("UPDATE personnes SET mois_naissance = NULL, jour_naissance = NULL, precision_naissance = 'environ' WHERE id IN (2, 9)");
});

test('CSV : BOM UTF-8, point-virgule, téléphone conservé, formule neutralisée', function () {
    $c = CsvExporter::fiches();
    vrai(str_starts_with($c, "\xEF\xBB\xBF"), 'BOM');
    vrai(str_contains(strtok($c, "\n"), 'N°;Nom;'), 'entête');
    vrai(str_contains($c, '+229 01 00 00 00 09'), 'téléphone en texte, jamais 2,29E+12');
    vrai(str_contains(CsvExporter::tableau(['a'], [['=SOMME(A1)']]), "'=SOMME"), 'formule');
    vrai(!str_contains(CsvExporter::tableau(['a'], [['+229 01 00 00 00 09']]), "'+229"), 'numéro intact');
    egal('+228 90 12 34 56', CsvExporter::telephone('+22890123456'));
});

test('Excel : vrai classeur, titres gris et gras, téléphone en texte', function () {
    [$titres, $lignes] = CsvExporter::donneesFiches();
    $x = \App\Services\XlsxExporter::tableau('Fiches', $titres, $lignes);
    $f = tempnam(sys_get_temp_dir(), 'xl');
    file_put_contents($f, $x);
    $z = new \ZipArchive();
    egal(true, $z->open($f), 'archive valide');
    foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $p) {
        vrai($z->locateName($p) !== false, "$p absent");
        vrai(simplexml_load_string((string) $z->getFromName($p)) !== false, "$p mal formé");
    }
    $feuille = (string) $z->getFromName('xl/worksheets/sheet1.xml');
    $styles = (string) $z->getFromName('xl/styles.xml');
    vrai(str_contains($feuille, '<c r="A1" s="1" t="inlineStr"><is><t xml:space="preserve">N°</t>'), 'titre N° stylé');
    vrai(str_contains($styles, '<b/>') && str_contains($styles, 'FFD9D9D9'), 'gris et gras');
    vrai(str_contains($feuille, 't="inlineStr"><is><t xml:space="preserve">+229 01 00 00 00 09</t>'), 'téléphone en texte');
    $z->close();
    unlink($f);
});

test('Arbre lisible : liens validés seulement, sans téléphone ni fiche privée', function () {
    [$titres, $lignes] = CsvExporter::donneesArbre();
    egal(['N°', 'Nom', 'Sexe', 'Naissance', 'Décès', 'Branche', 'Père', 'Mère', 'Conjoint(s)', 'Enfants'], $titres);
    $texte = json_encode($lignes, JSON_UNESCAPED_UNICODE);
    vrai(!str_contains($texte, 'Zinsou') && !str_contains($texte, 'Mawuena') && !preg_match('/\+\d{3}/', $texte), 'donnée exposée');
    $kossi = array_values(array_filter($lignes, static fn(array $l): bool => $l[0] === 9))[0];
    egal('Mawuli ZOCLI', $kossi[6]);
    egal('Afi ADJOVI', $kossi[7]);
    egal('vers 1968', $kossi[3]);
    $mawuli = array_values(array_filter($lignes, static fn(array $l): bool => $l[0] === 5))[0];
    egal('Afi ADJOVI, Akouvi HOUNSOU', $mawuli[8]);
});

test('Exports réservés à l\'admin et journalisés', function () {
    foreach (['/export/gedcom', '/export/csv'] as $r) {
        statut(api('GET', $r, null, connecter('+2290100000010')), 403);
    }
    vrai(str_contains(statut(api('GET', '/export/gedcom', null, jetonDirect(1)), 200)['brut'], '0 HEAD'), 'gedcom');
    statut(api('GET', '/export/csv', null, jetonDirect(1)), 200);
    egal(1, (int) Database::value("SELECT COUNT(*) FROM journal_actions WHERE action = 'export.gedcom'"));
});

section('Lot 3 — confidentialité');

test('Recette 6 : balayage de toutes les routes, un membre n\'obtient ni téléphone ni fiche privée', function () {
    $routeur = new Router();
    (require BASE_PATH . '/src/routes.php')($routeur);
    $j = connecter('+2290100000020');
    $moi = '+2290100000020';
    $maxId = (int) Database::value('SELECT MAX(id) FROM personnes');
    $liens = (int) Database::value('SELECT MAX(id) FROM liens_parente');
    $prives = array_column(Database::all('SELECT DISTINCT n.valeur FROM noms_personne n JOIN personnes p ON p.id = n.personne_id WHERE p.est_privee = 1 AND n.type <> \'nom_famille\''), 'valeur');
    vrai($prives !== [], 'aucune fiche privée en base');

    $verifier = static function (string $methode, string $url, array $rep) use ($moi, $prives): void {
        $brut = str_replace($moi, '', $rep['brut']);
        if (preg_match('/\+\d{8,15}/', $brut, $m)) {
            throw new \RuntimeException("Téléphone sur $methode $url : {$m[0]}");
        }
        foreach ($prives as $nom) {
            if (str_contains($brut, $nom)) {
                throw new \RuntimeException("Fiche privée « $nom » sur $methode $url");
            }
        }
        // Seules ses propres coordonnées (mawuena, Ouidah) peuvent revenir à un membre.
        foreach (['email', 'motif_prive', 'lieu_residence'] as $champ) {
            if (preg_match('/"' . $champ . '":"(?!mawuena|Ouidah)/', $brut)) {
                throw new \RuntimeException("Champ $champ sur $methode $url");
            }
        }
    };

    $appels = 0;
    foreach ($routeur->routes() as [$methode, $motif]) {
        if ($methode !== 'GET') {
            continue;
        }
        $urls = [$motif];
        if (str_contains($motif, '{id}')) {
            $max = str_contains($motif, '/liens') ? $liens : $maxId;
            $urls = array_map(static fn(int $i): string => str_replace('{id}', (string) $i, $motif), range(1, $max));
        } elseif (str_contains($motif, '{token}')) {
            $urls = [str_replace('{token}', str_repeat('b', 43), $motif)];
        }
        if ($motif === '/personnes/recherche') {
            $urls = array_map(static fn(string $q): string => '/personnes/recherche?per_page=100&q=' . rawurlencode($q), ['zocli', 'zinsou', 'ko', 'se']);
        }
        if ($motif === '/arbre') {
            $urls = array_map(static fn(int $i): string => "/arbre?profondeur=4&racine=$i", range(1, $maxId));
        }
        foreach ($urls as $url) {
            reinitialiserLimites();
            $verifier('GET', $url, api('GET', $url, null, $j));
            $appels++;
        }
    }
    foreach (['Zinsou', 'Kossi', 'Yao'] as $p) {
        for ($b = 1; $b <= 3; $b++) {
            $verifier('POST', '/rapprochement/candidats', api('POST', '/rapprochement/candidats', ['prenoms' => $p, 'branche_id' => $b, 'annee_naissance' => 1990], $j));
            $appels++;
        }
    }
    vrai($appels > 100, "seulement $appels appels");
    echo "      ($appels appels vérifiés)\n";
});

test('Recette 6 : routes d\'administration et de référent refusées au membre', function () {
    $routeur = new Router();
    (require BASE_PATH . '/src/routes.php')($routeur);
    $j = connecter('+2290100000020');
    $n = 0;
    foreach ($routeur->routes() as [$methode, $motif]) {
        if (preg_match('#^/(admin|export|taches|fusions|journal|stats|doublons|referent)#', $motif)) {
            $url = str_replace(['{id}', '{nom}'], ['1', 'relances'], $motif);
            statut(api($methode, $url, $methode === 'GET' ? null : [], $j), 403, "$methode $url");
            $n++;
        }
    }
    vrai($n >= 20, "$n routes");
});

test('Un référent n\'accède pas aux routes d\'administration', function () {
    $j = connecter('+2290100000010');
    foreach ([['GET', '/admin/utilisateurs'], ['POST', '/admin/branches'], ['PATCH', '/admin/parametres'], ['POST', '/taches/backup/lancer'], ['POST', '/fusions/1/annuler']] as [$m, $r]) {
        statut(api($m, $r, [], $j), 403, "$m $r");
    }
});

section('Lot 3 — administration (API)');

test('Branches, rôles, variantes et paramètres pilotés sans redéploiement', function () {
    $j = jetonDirect(1);
    $b = statut(api('POST', '/admin/branches', ['nom' => 'Branche Test', 'ordre' => 9], $j), 201)['data']['id'];
    statut(api('PATCH', "/admin/branches/$b", ['nom' => 'Branche Test 2', 'actif' => false], $j), 200);
    egal(3, count(statut(api('GET', '/branches', null, $j), 200)['data']), 'branche inactive masquée');
    statut(api('POST', '/admin/branches', ['nom' => 'Branche Test 2'], $j), 422, 'doublon de nom');

    statut(api('POST', '/admin/utilisateurs/12/role', ['role' => 'relais', 'branches_relais' => [2]], $j), 200);
    egal(1, (int) Database::value('SELECT COUNT(*) FROM relais_branche WHERE utilisateur_id = 12 AND branche_id = 2'));
    statut(api('POST', '/admin/utilisateurs/12/role', ['role' => 'membre'], $j), 200);
    statut(api('POST', '/admin/utilisateurs/1/role', ['role' => 'membre'], $j), 409, 'dernier admin');

    $v = statut(api('POST', '/admin/variantes', ['variante' => 'zoklly', 'forme_canonique' => 'zocli'], $j), 201)['data']['id'];
    statut(api('POST', '/admin/variantes/recalculer', [], $j), 200);
    \App\Services\NormalisationService::viderCache();
    egal('ZOCLI', \App\Services\NormalisationService::normaliser('Zoklly'));
    statut(api('DELETE', "/admin/variantes/$v", null, $j), 200);
    \App\Services\NormalisationService::viderCache();

    statut(api('PATCH', '/admin/parametres', ['seuil_proposition' => 'abc'], $j), 422);
    statut(api('PATCH', '/admin/parametres', ['inconnu' => 3], $j), 422);
    statut(api('PATCH', '/admin/parametres', ['seuil_proposition' => 45], $j), 200);
    egal('45', Database::value("SELECT valeur FROM parametres WHERE cle = 'seuil_proposition'"));
    statut(api('PATCH', '/admin/parametres', ['seuil_proposition' => 50], $j), 200);
    ParametreService::viderCache();
    vrai((int) Database::value("SELECT COUNT(*) FROM journal_actions WHERE action IN ('role.changement','parametre.changement','branche.enregistrement')") >= 5, 'journal');
});

section('Lot 3 — sauvegarde et restauration');

$empreinte = static function (): array {
    $res = [];
    foreach (Database::all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $t) {
        $table = array_values($t)[0];
        if (!in_array($table, ['rate_limits', 'sessions_api', 'otp_codes', 'taches_execution', 'journal_actions'], true)) {
            $colonnes = array_column(array_filter(Database::all("SHOW COLUMNS FROM `$table`"), static fn(array $c): bool => !str_contains((string) $c['Extra'], 'GENERATED')), 'Field');
            $res[$table] = md5(json_encode(Database::all("SELECT `" . implode('`,`', $colonnes) . "` FROM `$table` ORDER BY 1, 2")));
        }
    }
    return $res;
};

foreach (['mysqldump' => false, 'repli PHP' => true] as $nomOutil => $forcer) {
    test("Sauvegarde ($nomOutil) puis restauration complète à l'identique", function () use ($empreinte, $forcer, $nomOutil) {
        $b = BackupService::sauvegarder($forcer);
        egal($forcer ? 'php' : 'mysqldump', $b['outil']);
        vrai($b['taille'] > 1000, 'fichier vide');
        $avant = $empreinte();
        $journal = (int) Database::value('SELECT COUNT(*) FROM journal_actions');

        Database::pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        Database::run('TRUNCATE TABLE noms_personne');
        Database::run("UPDATE personnes SET lieu_residence = 'SINISTRE'");
        Database::pdo()->exec('DROP TRIGGER journal_no_update');
        Database::pdo()->exec('SET FOREIGN_KEY_CHECKS=1');

        vrai(BackupService::restaurer($b['fichier']) > 10, 'instructions');
        egal($avant, $empreinte(), 'contenu restauré');
        egal($journal, (int) Database::value('SELECT COUNT(*) FROM journal_actions'), 'journal restauré');
        $refus = false;
        try {
            Database::run("UPDATE journal_actions SET action = 'x' LIMIT 1");
        } catch (\PDOException) {
            $refus = true;
        }
        vrai($refus, 'déclencheur non restauré');
        $ligne = (int) Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'liens_parente' AND COLUMN_NAME = 'enfant_actif_id' AND EXTRA LIKE '%GENERATED%'");
        egal(1, $ligne, 'colonne générée');
        unlink(BackupService::chemin($b['fichier']));
    });
}

test('Sauvegarde : rotation à 7 quotidiennes et 4 hebdomadaires, nom de fichier contrôlé', function () {
    $d = BackupService::dossier();
    for ($i = 1; $i <= 9; $i++) {
        touch("$d/zocli_quotidienne_2026010{$i}_000000.sql.gz");
        touch("$d/zocli_hebdomadaire_2026010{$i}_000000.sql.gz");
    }
    $b = TacheService::lancer('backup');
    egal(7, count(glob("$d/zocli_quotidienne_*.gz")));
    egal(4, count(glob("$d/zocli_hebdomadaire_*.gz")));
    array_map('unlink', glob("$d/zocli_*.gz"));
    $refus = false;
    try {
        BackupService::chemin('../config/config.php');
    } catch (\App\Core\ApiException) {
        $refus = true;
    }
    vrai($refus, 'chemin arbitraire accepté');
});

test('Tâches : verrou contre deux exécutions simultanées, pseudo-cron', function () {
    $v = fopen(BASE_PATH . '/storage/locks/relances.lock', 'c');
    flock($v, LOCK_EX);
    statut(api('POST', '/taches/relances/lancer', [], jetonDirect(1)), 409);
    flock($v, LOCK_UN);
    fclose($v);
    statut(api('POST', '/taches/relances/lancer', [], jetonDirect(1)), 200);
    statut(api('POST', '/taches/inconnue/lancer', [], jetonDirect(1)), 404);
    Database::run('DELETE FROM taches_execution');
    Database::run("INSERT INTO taches_execution (nom, derniere_execution) VALUES ('backup', UTC_TIMESTAMP()), ('relances', UTC_TIMESTAMP())");
    egal('detect_doublons', TacheService::rattraper());
    egal(null, TacheService::rattraper(), 'une seule fois par période');
});
