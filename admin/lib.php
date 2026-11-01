<?php
declare(strict_types=1);

// Sécurité et rendu communs du panneau : session, CSRF, droits, gabarit.
use App\Core\ApiException;
use App\Core\Database;
use App\Services\PermissionService;

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function demarrerSession(): void
{
    session_name('ZOCLI_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function jetonCsrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function champCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(jetonCsrf()) . '">';
}

function verifierCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }
}

function utilisateurCourant(): ?array
{
    $id = $_SESSION['utilisateur_id'] ?? null;
    if ($id === null) {
        return null;
    }
    $u = Database::one('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1', [$id]);
    if ($u === null || !PermissionService::estAdmin($u)) {
        $_SESSION = [];
        return null;
    }
    return $u;
}

function url(string $page, array $q = []): string
{
    return 'index.php?' . http_build_query(['p' => $page] + $q);
}

function rediriger(string $page, array $q = []): never
{
    header('Location: ' . url($page, $q));
    exit;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = [$message, $type];
}

/** Exécute une action de formulaire et transforme les refus métier en message. */
function action(callable $fn, string $succes, string $page, array $q = []): never
{
    try {
        $fn();
        flash($succes);
    } catch (ApiException $ex) {
        flash($ex->getMessage(), 'erreur');
    }
    rediriger($page, $q);
}

function icone(string $nom): string
{
    $traces = [
        'tableau' => '<path d="M4 13h6V4H4zM14 20h6V4h-6zM4 20h6v-3H4z"/>',
        'file' => '<path d="M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2"/>',
        'doublons' => '<circle cx="9" cy="9" r="5"/><circle cx="15" cy="15" r="5"/>',
        'fusions' => '<path d="M6 4v5a6 6 0 0 0 6 6h6M15 12l3 3-3 3M18 4v3"/>',
        'branches' => '<path d="M12 3v7m0 0H6v4m6-4h6v4M4 14h4v6H4zM10 14h4v6h-4zM16 14h4v6h-4z"/>',
        'utilisateurs' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-4-6"/>',
        'variantes' => '<path d="M4 7V5h16v2M12 5v14M9 19h6"/>',
        'parametres' => '<path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h8M16 18h4"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="14" cy="18" r="2"/>',
        'journal' => '<path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h3"/>',
        'exports' => '<path d="M12 4v11m0 0l-4-4m4 4l4-4M4 20h16"/>',
        'taches' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($traces[$nom] ?? '') . '</svg>';
}

function haut(string $titre, ?array $u, string $actif = '', string $sousTitre = '', string $outils = ''): void
{
    $groupes = [
        'Pilotage' => ['tableau' => 'Tableau de bord'],
        'Lignée' => ['file' => 'Liens à valider', 'doublons' => 'Doublons', 'fusions' => 'Fusions', 'branches' => 'Fondateur et branches'],
        'Personnes' => ['utilisateurs' => 'Utilisateurs et rôles'],
        'Réglages' => ['parametres' => 'Paramètres', 'variantes' => 'Variantes de noms'],
        'Contrôle' => ['journal' => 'Journal', 'exports' => 'Exports', 'taches' => 'Tâches et sauvegardes'],
    ];
    $compteurs = [
        'file' => (int) Database::value("SELECT COUNT(*) FROM liens_parente WHERE statut IN ('confirme','en_examen')"),
        'doublons' => (int) Database::value("SELECT COUNT(*) FROM doublons_suspects WHERE statut = 'a_traiter'"),
    ];
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($titre) . ' — ' . e(APP_NAME) . '</title><link rel="icon" type="image/svg+xml" href="assets/logo.svg"><link rel="icon" type="image/svg+xml" href="assets/logo.svg"><link rel="stylesheet" href="assets/admin.css"></head><body><div class="app">';
    echo '<aside class="barre"><div class="marque"><img class="logo" src="assets/logo.svg" alt=""><div><strong>Recensement ZOCLI</strong><span>Administration</span></div></div>';
    echo '<nav aria-label="Menu principal">';
    foreach ($groupes as $groupe => $items) {
        echo '<div class="groupe">' . e($groupe) . '</div>';
        foreach ($items as $cle => $lib) {
            $n = $compteurs[$cle] ?? 0;
            echo '<a class="item' . ($cle === $actif ? ' actif" aria-current="page' : '') . '" href="' . e(url($cle)) . '">' . icone($cle) . e($lib)
                . ($n > 0 ? '<span class="compteur">' . $n . '</span>' : '') . '</a>';
        }
    }
    echo '</nav><div class="compte"><div class="qui">' . e($u['email'] ?? '') . '</div>';
    echo '<form method="post" action="' . e(url('deconnexion')) . '" data-confirmer="Voulez-vous vraiment vous déconnecter ?" data-oui="Oui, me déconnecter" data-non="Non, rester connecté">' . champCsrf() . '<button class="petit">Se déconnecter</button></form>';
    echo '</div></aside><div class="voile" data-menu></div><div class="page"><header class="entete"><button type="button" class="ouvrir-menu" data-menu aria-label="Ouvrir le menu" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="titre"><h1>' . e($titre) . '</h1>' . ($sousTitre !== '' ? '<p>' . e($sousTitre) . '</p>' : '') . '</div>';
    echo $outils !== '' ? '<div class="outils">' . $outils . '</div>' : '';
    echo '</header><main class="contenu">';
    if (isset($_SESSION['flash'])) {
        [$m, $t] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alerte ' . e($t) . '" role="status">' . e($m) . '</div>';
    }
}

function bas(): void
{
    echo '</main></div></div><dialog id="confirmation"><div class="fenetre"><h2>Confirmation</h2><p data-message></p>'
        . '<div class="boutons"><button type="button" class="contour" data-non>Non</button><button type="button" data-oui>Oui</button></div></div></dialog>'
        . '<script src="assets/admin.js"></script></body></html>';
}

const LIBELLES_STATUT = ['declare' => 'Déclaré', 'confirme' => 'Confirmé', 'valide' => 'Validé', 'en_examen' => 'En examen', 'prive' => 'Privé', 'rejete' => 'Rejeté'];

function badge(string $statut): string
{
    return '<span class="badge ' . e($statut) . '">' . e(LIBELLES_STATUT[$statut] ?? $statut) . '</span>';
}

function badgeRole(string $role): string
{
    $l = ['membre' => 'Membre', 'relais' => 'Relais', 'referent' => 'Référent', 'admin' => 'Administrateur'];
    return '<span class="badge ' . e($role) . '">' . e($l[$role] ?? $role) . '</span>';
}

/** Barre empilée en SVG : la politique de sécurité du panneau interdit les styles en ligne. */
function barreStatuts(array $liens): string
{
    $total = array_sum($liens);
    $svg = '<svg class="barre-statuts" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="s-fond" width="100" height="10"/>';
    $x = 0.0;
    foreach (['valide', 'confirme', 'declare', 'en_examen', 'prive', 'rejete'] as $s) {
        if ($total > 0 && ($liens[$s] ?? 0) > 0) {
            $l = $liens[$s] / $total * 100;
            $svg .= '<rect class="s-' . $s . '" x="' . round($x, 2) . '" width="' . round($l, 2) . '" height="10"/>';
            $x += $l;
        }
    }
    return $svg . '</svg>';
}

function libelleAction(string $action): string
{
    $l = ['auth.connexion' => 'Connexion', 'auth.deconnexion' => 'Déconnexion', 'personne.creation' => 'Fiche créée', 'personne.modification' => 'Fiche modifiée',
        'personne.retrait' => 'Fiche retirée', 'personne.revendication' => 'Fiche reprise par son titulaire', 'personne.rattachement' => 'Changement de branche',
        'personne.consultation_privee' => 'Consultation d\'une fiche privée', 'lien.declaration' => 'Lien déclaré', 'lien.invitation' => 'Invitation envoyée à un proche',
        'invitation.reponse' => 'Réponse d\'un proche', 'lien.confirmation' => 'Lien confirmé', 'lien.contestation' => 'Lien contesté', 'lien.validation' => 'Lien validé',
        'lien.rejet' => 'Lien rejeté', 'lien.examen' => 'Lien en examen', 'lien.prive' => 'Lien passé en privé', 'lien.relance' => 'Relance envoyée',
        'fusion.effectuee' => 'Fusion de fiches', 'fusion.annulee' => 'Fusion annulée', 'doublon.distinct' => 'Personnes distinctes', 'consentement.accepte' => 'Consentement',
        'union.declaration' => 'Union déclarée', 'union.validation' => 'Union validée', 'union.creation' => 'Union créée', 'role.changement' => 'Changement de rôle',
        'parametre.changement' => 'Paramètre modifié', 'branche.enregistrement' => 'Branche enregistrée', 'fondateur.enregistrement' => 'Fondateur enregistré',
        'export.gedcom' => 'Export GEDCOM', 'export.csv' => 'Export CSV', 'export.journal' => 'Export du journal', 'tache.execution' => 'Tâche exécutée',
        'tache.lancement_manuel' => 'Tâche lancée', 'sauvegarde.restauration' => 'Restauration', 'sauvegarde.telechargement' => 'Sauvegarde téléchargée',
        'compte.modification' => 'Accès modifiés', 'compte.reactivation' => 'Compte rétabli', 'compte.email' => 'Email changé',
        'variante.enregistrement' => 'Variante ajoutée', 'variante.suppression' => 'Variante supprimée', 'variante.recalcul' => 'Noms recalculés'];
    return $l[$action] ?? $action;
}

/** Navigation de liste : les pages ne défilent pas, elles se tournent. */
function pagination(string $page, int $courante, int $parPage, int $total, array $q = []): string
{
    $pages = max(1, (int) ceil($total / $parPage));
    if ($pages === 1) {
        return '';
    }
    $html = '<div class="pages">';
    if ($courante > 1) {
        $html .= '<a class="bouton contour petit" href="' . e(url($page, $q + ['n' => $courante - 1])) . '">Précédent</a>';
    }
    $html .= '<span>Page ' . $courante . ' sur ' . $pages . '</span>';
    if ($courante < $pages) {
        $html .= '<a class="bouton contour petit" href="' . e(url($page, $q + ['n' => $courante + 1])) . '">Suivant</a>';
    }
    return $html . '</div>';
}

function exigerAdminPage(array $u): void
{
    if (!PermissionService::estAdmin($u)) {
        http_response_code(403);
        exit('Accès réservé à l\'administrateur général.');
    }
}
