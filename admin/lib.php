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
    if ($u === null || !PermissionService::estReferent($u)) {
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

function haut(string $titre, ?array $u, string $actif = ''): void
{
    $menu = ['tableau' => 'Tableau de bord', 'file' => 'File de validation', 'doublons' => 'Doublons', 'journal' => 'Journal'];
    if ($u !== null && PermissionService::estAdmin($u)) {
        $menu = ['tableau' => 'Tableau de bord', 'branches' => 'Fondateur et branches', 'utilisateurs' => 'Utilisateurs et rôles',
            'file' => 'File de validation', 'doublons' => 'Doublons', 'fusions' => 'Fusions', 'variantes' => 'Variantes de noms',
            'parametres' => 'Paramètres', 'journal' => 'Journal', 'exports' => 'Exports', 'taches' => 'Tâches et sauvegardes'];
    }
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($titre) . ' — ' . e(APP_NAME) . '</title><link rel="stylesheet" href="assets/admin.css"></head><body>';
    echo '<header><strong>' . e(APP_NAME) . '</strong>';
    if ($u !== null) {
        echo '<form method="post" action="' . e(url('deconnexion')) . '">' . champCsrf() . '<button>Se déconnecter</button></form>';
    }
    echo '</header>';
    if ($u !== null) {
        echo '<nav aria-label="Menu">';
        foreach ($menu as $cle => $lib) {
            echo '<a href="' . e(url($cle)) . '"' . ($cle === $actif ? ' class="actif" aria-current="page"' : '') . '>' . e($lib) . '</a>';
        }
        echo '</nav>';
    }
    echo '<main>';
    if (isset($_SESSION['flash'])) {
        [$m, $t] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alerte ' . e($t) . '" role="status">' . e($m) . '</div>';
    }
}

function bas(): void
{
    echo '</main><script src="assets/admin.js"></script></body></html>';
}

function badge(string $statut): string
{
    $libelles = ['declare' => 'Déclaré', 'confirme' => 'Confirmé', 'valide' => 'Validé', 'en_examen' => 'En examen', 'prive' => 'Privé', 'rejete' => 'Rejeté'];
    return '<span class="badge ' . e($statut) . '">' . e($libelles[$statut] ?? $statut) . '</span>';
}

function exigerAdminPage(array $u): void
{
    if (!PermissionService::estAdmin($u)) {
        http_response_code(403);
        haut('Accès refusé', $u);
        echo '<div class="alerte erreur">Cette page est réservée à l\'administrateur général.</div>';
        bas();
        exit;
    }
}
