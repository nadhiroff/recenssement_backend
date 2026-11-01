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

$connexionReferent = static function (): Navigateur {
    $n = new Navigateur();
    $n->poster('connexion', ['mode' => 'referent', 'telephone' => '+2290100000010']);
    $n->poster('connexion', ['mode' => 'code', 'code' => dernierOtp('+2290100000010')]);
    return $n;
};

test('Admin : mot de passe faux refusé, sans page protégée sans session', function () {
    $n = new Navigateur();
    $n->aller('index.php?p=tableau');
    egal(302, $n->http);
    vrai(str_contains($n->lieu, 'p=connexion'), 'redirection');
    $n->poster('connexion', ['mode' => 'admin', 'email' => 'admin.demo@example.test', 'mot_de_passe' => 'mauvais']);
    vrai(str_contains($n->corps, 'Identifiants incorrects'), 'message');
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
        vrai(!str_contains($n->corps, 'Warning') && !str_contains($n->corps, 'Fatal'), "erreur PHP sur $p");
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

test('Droits : un référent n\'accède pas aux pages admin, mais à ses files', function () use ($connexionReferent) {
    $n = $connexionReferent();
    foreach (['branches', 'utilisateurs', 'fusions', 'variantes', 'parametres', 'exports', 'taches'] as $p) {
        $n->aller("index.php?p=$p");
        egal(403, $n->http, $p);
    }
    foreach (['tableau', 'file', 'doublons', 'journal'] as $p) {
        $n->aller("index.php?p=$p");
        egal(200, $n->http, $p);
    }
    $n->aller('index.php?p=exports&telecharger=csv');
    egal(403, $n->http);
    vrai(!str_contains($n->corps, '+229'), 'CSV exposé');
});

test('Droits : un membre n\'entre pas dans le panneau', function () {
    $n = new Navigateur();
    $n->poster('connexion', ['mode' => 'referent', 'telephone' => '+2290100000020']);
    $n->poster('connexion', ['mode' => 'code', 'code' => '123456']);
    $n->aller('index.php?p=tableau');
    egal(302, $n->http);
});

test('Référent web : valide un lien par le panneau, conflit affiché', function () use ($connexionReferent) {
    Database::run("UPDATE liens_parente SET statut = 'confirme' WHERE id = 22");
    $n = $connexionReferent();
    $n->aller('index.php?p=lien&id=22');
    vrai(str_contains($n->corps, 'Valider'), 'bouton');
    $n->poster('file', ['id' => 22, 'faire' => 'valider']);
    egal('valide', Database::value('SELECT statut FROM liens_parente WHERE id = 22'));
    $conflit = (int) Database::insert('liens_parente', ['parent_id' => 10, 'enfant_id' => (int) Database::insert('personnes', ['sexe' => 'M', 'branche_id' => 1]), 'role_parent' => 'mere', 'statut' => 'confirme']);
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
