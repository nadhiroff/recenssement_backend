<?php
declare(strict_types=1);

use App\Core\Database;

section('Admin web');

/** Mini-navigateur : conserve le cookie de session. */
final class Navigateur
{
    public string $cookie = '';
    public int $http = 0;
    public string $corps = '';
    public string $lieu = '';

    public function aller(string $chemin, ?array $post = null): string
    {
        $ch = curl_init('http://127.0.0.1:' . TEST_PORT . '/admin/' . $chemin);
        $h = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
        if ($this->cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $rep = (string) curl_exec($ch);
        $this->http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $taille = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $entetes = substr($rep, 0, $taille);
        $this->corps = substr($rep, $taille);
        if (preg_match('/Set-Cookie: (ZOCLI_ADMIN=[^;]+)/i', $entetes, $m)) {
            $this->cookie = $m[1];
        }
        $this->lieu = preg_match('/^Location: (.+)$/mi', $entetes, $m) ? trim($m[1]) : '';
        $GLOBALS['dernier_entetes'] = $entetes;
        return $this->corps;
    }

    public function csrf(string $page): string
    {
        $this->aller("index.php?p=$page");
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $this->corps, $m);
        return $m[1] ?? '';
    }

    public function poster(string $page, array $donnees): string
    {
        return $this->aller("index.php?p=$page", $donnees + ['csrf' => $this->csrf($page)]);
    }
}

$connexionAdmin = static function (): Navigateur {
    $n = new Navigateur();
    $n->poster('connexion', ['mode' => 'admin', 'email' => 'admin.demo@example.test', 'mot_de_passe' => 'DemoAdmin2026!']);
    $n->poster('connexion', ['mode' => 'code', 'code' => dernierOtp('admin.demo@example.test')]);
    return $n;
};

test('Admin : mot de passe faux refusé, sans page protégée sans session', function () {
    $n = new Navigateur();
    $n->aller('index.php?p=tableau');
    egal(302, $n->http);
    vrai(str_contains($n->lieu, 'p=connexion'), 'redirection');
    $n->poster('connexion', ['mode' => 'admin', 'email' => 'admin.demo@example.test', 'mot_de_passe' => 'mauvais']);
    vrai(str_contains($n->corps, 'mot de passe incorrect'), 'message');
});

test('Admin : connexion en deux facteurs, en-têtes de sécurité, cookie durci', function () use ($connexionAdmin) {
    $n = new Navigateur();
    $n->aller('index.php?p=connexion');
    $h = strtolower($GLOBALS['dernier_entetes']);
    vrai(str_contains($h, 'httponly') && str_contains($h, 'samesite=strict'), 'cookie');
    vrai(str_contains($h, 'content-security-policy') && str_contains($h, 'x-frame-options: deny'), 'en-têtes');
    $n = $connexionAdmin();
    $n->aller('index.php?p=tableau');
    egal(200, $n->http);
    vrai(str_contains($n->corps, 'Tableau de bord'), 'tableau');
});

test('Admin : une requête POST sans jeton CSRF est refusée', function () use ($connexionAdmin) {
    $n = $connexionAdmin();
    $n->aller('index.php?p=branches', ['nom' => 'Pirate']);
    egal(403, $n->http);
    egal(0, (int) Database::value("SELECT COUNT(*) FROM branches WHERE nom = 'Pirate'"));
});

test('Admin : toutes les pages répondent', function () use ($connexionAdmin) {
    $n = $connexionAdmin();
    foreach (['tableau', 'branches', 'utilisateurs', 'file', 'doublons', 'fusions', 'variantes', 'parametres', 'journal', 'exports', 'taches'] as $p) {
        $n->aller("index.php?p=$p");
        egal(200, $n->http, $p);
        vrai(!preg_match('/Warning|Fatal|Deprecated|Notice|Uncaught/', $n->corps), "erreur PHP sur $p");
    }
    $n->aller('index.php?p=inconnue');
    egal(404, $n->http);
});

test('Admin : saisie du fondateur et d\'une branche, échappement HTML', function () use ($connexionAdmin) {
    $n = $connexionAdmin();
    $n->poster('branches', ['faire' => 'fondateur', 'nom_famille' => 'ZOCLI', 'prenoms' => 'Agbo', 'annee_naissance' => '1885', 'precision_naissance' => 'environ']);
    egal(1885, (int) Database::value('SELECT annee_naissance FROM personnes WHERE est_fondateur = 1'));
    egal(1, (int) Database::value('SELECT COUNT(*) FROM personnes WHERE est_fondateur = 1'));
    $n->poster('branches', ['nom' => 'B<script>x</script>', 'actif' => '1']);
    $n->aller('index.php?p=branches');
    vrai(!str_contains($n->corps, '<script>x'), 'XSS');
});

test('Admin : paramètres, rôles, tâches et sauvegardes par le panneau', function () use ($connexionAdmin) {
    $n = $connexionAdmin();
    $n->poster('parametres', ['seuil_doublon' => '75']);
    egal('75', Database::value("SELECT valeur FROM parametres WHERE cle = 'seuil_doublon'"));
    $n->poster('parametres', ['seuil_doublon' => '70']);
    $n->poster('utilisateurs', ['id' => 12, 'role' => 'relais', 'branches' => [1]]);
    egal('relais', Database::value('SELECT role_global FROM utilisateurs WHERE id = 12'));
    $n->poster('utilisateurs', ['id' => 12, 'role' => 'membre']);
    $n->poster('taches', ['nom' => 'backup']);
    $fichiers = glob(BASE_PATH . '/storage/backups/zocli_quotidienne_*.gz');
    vrai(count($fichiers) >= 1, 'sauvegarde');
    $f = basename($fichiers[0]);
    $n->aller('index.php?p=taches&telecharger=' . rawurlencode($f));
    vrai(str_starts_with($n->corps, "\x1f\x8b"), 'téléchargement gzip');
    $n->poster('taches', ['faire' => 'restaurer', 'fichier' => $f, 'confirmation' => 'non']);
    vrai(str_contains($n->aller('index.php?p=taches'), 'Tapez RESTAURER pour confirmer'), 'restauration sans le mot de confirmation');
    array_map('unlink', glob(BASE_PATH . '/storage/backups/zocli_*.gz'));
});

test('Racine du backend : redirige vers l\'administration', function () {
    $ch = curl_init('http://127.0.0.1:' . TEST_PORT . '/index.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    $r = (string) curl_exec($ch);
    egal(302, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE));
    vrai(str_contains($r, 'Location: admin/index.php'), 'redirection');
});

test('Admin : accès perdu rétabli, compte retiré réactivé', function () use ($connexionAdmin) {
    $n = $connexionAdmin();
    $id = (int) Database::insert('utilisateurs', ['telephone_whatsapp' => '+2290161000040', 'email' => 'perdu@example.test']);
    $p = (int) Database::insert('personnes', ['sexe' => 'M', 'statut_fiche' => 'retiree']);
    Database::run('UPDATE utilisateurs SET personne_id = ?, actif = 0 WHERE id = ?', [$p, $id]);
    $n->poster('utilisateurs', ['id' => $id, 'faire' => 'compte', 'telephone' => '+2290161000041', 'email' => 'retrouve@example.test']);
    egal('retrouve@example.test', Database::value('SELECT email FROM utilisateurs WHERE id = ?', [$id]));
    $n->poster('utilisateurs', ['id' => $id, 'faire' => 'reactiver']);
    egal(1, (int) Database::value('SELECT actif FROM utilisateurs WHERE id = ?', [$id]));
    egal('active', Database::value('SELECT statut_fiche FROM personnes WHERE id = ?', [$p]));
    egal('+2290161000041', Database::value('SELECT telephone_whatsapp FROM personnes WHERE id = ?', [$p]));
});

test('Panneau : formulaires CSRF, boutons de sortie confirmés, pagination', function () use ($connexionAdmin) {
    $n = $connexionAdmin();
    $n->aller('index.php?p=tableau');
    vrai(str_contains($n->corps, 'Voulez-vous vraiment vous déconnecter'), 'déconnexion sans confirmation');
    vrai(!preg_match('/ style="/', $n->corps), 'style en ligne bloqué par la politique de sécurité');
    $n->aller('index.php?p=utilisateurs&q=kossi');
    vrai(str_contains($n->corps, 'Kossi ZOCLI') && !str_contains($n->corps, 'Enyonam ZOCLI'), 'recherche');
    $n->aller('index.php?p=utilisateurs&role=referent');
    vrai(!str_contains($n->corps, 'Edem ZOCLI'), 'filtre par rôle');
});

test('Paramètres : chaque réglage de la page est lu et borné', function () use ($connexionAdmin) {
    $n = $connexionAdmin();
    $n->aller('index.php?p=parametres');
    foreach (['score_prenom_commun', 'score_meme_pere', 'score_meme_branche', 'score_naissance_5ans', 'score_meme_mere', 'seuil_proposition', 'seuil_doublon', 'ecart_annees_max', 'delai_relance_jours', 'delai_relance_suivante_jours', 'nb_relances_max', 'arbre_visible_membres', 'doublon_prenom_requis', 'generations_lignee_feminine'] as $c) {
        vrai(str_contains($n->corps, 'name="' . $c . '"'), "$c absent");
    }
    vrai(!str_contains($n->corps, 'nature_lien_active'), 'réglage sans effet affiché');
    $n->poster('parametres', ['nb_relances_max' => '99']);
    vrai(str_contains($n->aller('index.php?p=parametres'), 'entre 0 et 12'), 'borne');
    egal('3', Database::value("SELECT valeur FROM parametres WHERE cle = 'nb_relances_max'"));
    $n->poster('parametres', ['arbre_visible_membres' => '0']);
    statut(api('GET', '/arbre', null, connecter('+2290100000020')), 403, 'arbre fermé depuis le panneau');
    $n->poster('parametres', ['arbre_visible_membres' => '1']);
    statut(api('GET', '/arbre', null, connecter('+2290100000020')), 200);
    $n->poster('parametres', ['nb_relances_max' => '0']);
    \App\Services\ParametreService::viderCache();
    $l = (int) Database::insert('liens_parente', ['parent_id' => 11, 'enfant_id' => (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 1]), 'role_parent' => 'pere', 'declarant_utilisateur_id' => 12, 'declare_le' => gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
    egal(0, \App\Services\LienService::relancer(), 'nb_relances_max');
    $n->poster('parametres', ['nb_relances_max' => '3']);
    \App\Services\ParametreService::viderCache();
    Database::run('UPDATE liens_parente SET statut = \'en_examen\' WHERE id = ?', [$l]);
    $n->poster('parametres', ['seuil_proposition' => '200']);
    egal([], statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'Kossi', 'annee_naissance' => 1968, 'pere_id' => 5, 'branche_id' => 1], connecter('+2290100000020')), 200)['data'], 'seuil_proposition');
    $n->poster('parametres', ['seuil_proposition' => '50']);
    egal(9, statut(api('POST', '/rapprochement/candidats', ['prenoms' => 'Kossi', 'annee_naissance' => 1968, 'pere_id' => 5, 'branche_id' => 1], connecter('+2290100000020')), 200)['data'][0]['personne']['id']);
    $n->poster('parametres', ['ecart_annees_max' => '0']);
    \App\Services\ParametreService::viderCache();
    egal(0, \App\Services\MatchingService::poids()['ecart']);
    $n->poster('parametres', ['ecart_annees_max' => '5']);
    \App\Services\ParametreService::viderCache();
});

test('Connexion admin : les réussites ne bloquent pas, les échecs oui', function () use ($connexionAdmin) {
    reinitialiserLimites();
    for ($i = 0; $i < 12; $i++) {
        $connexionAdmin();
    }
    $n = $connexionAdmin();
    $n->aller('index.php?p=tableau');
    egal(200, $n->http, 'admin bloqué par ses propres connexions');
    $n = new Navigateur();
    for ($i = 0; $i < 9; $i++) {
        $n->poster('connexion', ['mode' => 'admin', 'email' => 'admin.demo@example.test', 'mot_de_passe' => 'faux']);
    }
    vrai(str_contains($n->corps, 'Trop de tentatives'), 'échecs répétés');
    reinitialiserLimites();
});

test('Connexion : page unique, sans création de compte ni accès référent', function () {
    $n = new Navigateur();
    $n->aller('index.php?p=connexion');
    egal(1, substr_count($n->corps, '<form'), 'un seul formulaire');
    vrai(str_contains($n->corps, 'page-connexion') && !preg_match('/créer|référent|inscri/i', $n->corps), 'contenu');
});

test('Droits : seul l\'administrateur entre, ni référent ni membre', function () {
    foreach (['+2290100000010', '+2290100000020'] as $tel) {
        $n = new Navigateur();
        $n->poster('connexion', ['mode' => 'referent', 'telephone' => $tel]);
        $n->aller('index.php?p=tableau');
        egal(302, $n->http, $tel);
    }
    $n = new Navigateur();
    $n->poster('connexion', ['mode' => 'admin', 'email' => 'kossi.demo@example.test', 'mot_de_passe' => 'DemoAdmin2026!']);
    vrai(str_contains($n->corps, 'mot de passe incorrect'), 'référent refusé');
});

test('Administrateur unique : personne d\'autre ne peut le devenir', function () use ($connexionAdmin) {
    statut(api('POST', '/admin/utilisateurs/12/role', ['role' => 'admin'], jetonDirect(1)), 409);
    $n = $connexionAdmin();
    $n->aller('index.php?p=utilisateurs');
    egal(1, substr_count($n->corps, '<option value="admin"'), 'option réservée au compte admin');
});

test('Admin web : valide un lien, conflit affiché', function () use ($connexionAdmin) {
    Database::run("UPDATE liens_parente SET statut = 'confirme' WHERE id = 22");
    $n = $connexionAdmin();
    $n->aller('index.php?p=lien&id=22');
    vrai(str_contains($n->corps, 'Valider'), 'bouton');
    $n->poster('file', ['id' => 22, 'faire' => 'valider']);
    egal('valide', Database::value('SELECT statut FROM liens_parente WHERE id = 22'));
    $conflit = (int) Database::insert('liens_parente', ['parent_id' => 25, 'enfant_id' => (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 1]), 'role_parent' => 'pere', 'statut' => 'confirme']);
    $n->aller("index.php?p=lien&id=$conflit");
    vrai(str_contains($n->corps, 'autre référent') && !str_contains($n->corps, '>Valider<'), 'conflit');
});

test('Admin : annulation d\'une fusion depuis le panneau', function () use ($connexionAdmin) {
    $a = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 3]);
    $b = (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 3]);
    $f = \App\Services\FusionService::fusionner(Database::one('SELECT * FROM utilisateurs WHERE id = 1'), $a, $b);
    $n = $connexionAdmin();
    $n->poster('fusions', ['id' => $f]);
    vrai(Database::value('SELECT annulee_le FROM fusions WHERE id = ?', [$f]) !== null, 'annulée');
});
