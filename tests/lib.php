<?php
declare(strict_types=1);

// Mini-lanceur : assertions, appels HTTP et serveur local de test.

const TEST_DB = 'recensement_zocli_test';
const TEST_PORT = 8899;
putenv('ZOCLI_TEST_DB=' . TEST_DB);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Database;

$GLOBALS['tests'] = ['ok' => 0, 'ko' => 0, 'echecs' => [], 'liste' => []];

function test(string $nom, callable $fn): void
{
    try {
        reinitialiserLimites();
        $fn();
        $GLOBALS['tests']['ok']++;
        $GLOBALS['tests']['liste'][] = ['nom' => $nom, 'ok' => true];
        echo "  \033[32m✓\033[0m $nom\n";
    } catch (\Throwable $e) {
        $GLOBALS['tests']['ko']++;
        $GLOBALS['tests']['echecs'][] = $nom;
        $GLOBALS['tests']['liste'][] = ['nom' => $nom, 'ok' => false];
        echo "  \033[31m✗ $nom\033[0m\n      " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}

function section(string $titre): void
{
    echo "\n$titre\n";
}

function egal(mixed $attendu, mixed $obtenu, string $msg = ''): void
{
    if ($attendu !== $obtenu) {
        throw new \RuntimeException(trim("$msg : attendu " . json_encode($attendu, JSON_UNESCAPED_UNICODE) . ', obtenu ' . json_encode($obtenu, JSON_UNESCAPED_UNICODE)));
    }
}

function vrai(mixed $v, string $msg = 'condition fausse'): void
{
    if (!$v) {
        throw new \RuntimeException($msg);
    }
}

function statut(array $rep, int $http, string $msg = ''): array
{
    if ($rep['http'] !== $http) {
        throw new \RuntimeException(trim("$msg HTTP attendu $http, obtenu {$rep['http']} : " . substr($rep['brut'], 0, 300)));
    }
    return $rep;
}

function api(string $methode, string $route, ?array $corps = null, ?string $jeton = null, bool $parRoute = false): array
{
    $url = 'http://127.0.0.1:' . TEST_PORT . '/api/index.php/v1' . $route;
    if ($parRoute) {
        $url = 'http://127.0.0.1:' . TEST_PORT . '/api/index.php?route=/v1' . str_replace('?', '&', $route);
    }
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($jeton !== null) {
        $h[] = 'Authorization: Bearer ' . $jeton;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_ENCODING => '',
    ]);
    if ($corps !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps));
    }
    $brut = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = json_decode($brut, true);
    return ['http' => $http, 'brut' => $brut, 'json' => $json, 'data' => $json['data'] ?? null, 'error' => $json['error'] ?? null];
}

function dernierOtp(string $identifiant): string
{
    $masque = substr(hash('sha256', $identifiant), 0, 12);
    $lignes = file(BASE_PATH . '/storage/logs/otp.log', FILE_IGNORE_NEW_LINES) ?: [];
    foreach (array_reverse($lignes) as $l) {
        if (str_contains($l, " $masque ")) {
            return substr($l, -6);
        }
    }
    throw new \RuntimeException('OTP introuvable');
}

/** Connexion réelle par le parcours OTP. */
function connecter(string $telephone, ?string $email = null): string
{
    statut(api('POST', '/auth/otp/request', ['telephone' => $telephone, 'email' => $email]), 200, 'otp request');
    $rep = statut(api('POST', '/auth/otp/verify', ['telephone' => $telephone, 'code' => dernierOtp($telephone)]), 200, 'otp verify');
    return $rep['data']['token'];
}

/** Session API directe, pour les comptes sans téléphone (admin). */
function jetonDirect(int $utilisateurId): string
{
    return \App\Core\Auth::creerSession($utilisateurId, 'test')[0];
}

function reinitialiserLimites(): void
{
    Database::run('DELETE FROM rate_limits');
}

function uuid(): string
{
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}

function identiteTest(string $prenoms, array $extra = []): array
{
    return $extra + ['nom_famille' => 'ZOCLI', 'prenoms' => $prenoms, 'sexe' => 'M', 'annee_naissance' => 1990, 'precision_naissance' => 'environ', 'lieu_residence' => 'Cotonou'];
}

function reinitialiserBase(): void
{
    $pdoRoot = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS);
    $pdoRoot->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
    $pdoRoot->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $cmd = sprintf(
        'mysql --default-character-set=utf8mb4 -h%s -P%d -u%s %s %s < %s 2>&1',
        escapeshellarg(DB_HOST), DB_PORT, escapeshellarg(DB_USER), '--password=' . escapeshellarg(DB_PASS), TEST_DB, '%s'
    );
    foreach (['schema.sql', 'seed_demo.sql'] as $f) {
        exec(sprintf($cmd, escapeshellarg(BASE_PATH . '/database/' . $f)), $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException("Import $f : " . implode("\n", $out));
        }
    }
    Database::reset();
    \App\Services\ParametreService::viderCache();
    \App\Services\NormalisationService::viderCache();
}

function demarrerServeur(): void
{
    $cmd = sprintf(
        'ZOCLI_TEST_DB=%s exec php -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!',
        TEST_DB, TEST_PORT, escapeshellarg(BASE_PATH)
    );
    $GLOBALS['serveur'] = (int) shell_exec($cmd);
    register_shutdown_function(static fn() => posix_kill($GLOBALS['serveur'], 15));
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', TEST_PORT, $e, $s, 0.1)) {
            return;
        }
        usleep(100000);
    }
    throw new \RuntimeException('Serveur de test injoignable');
}
