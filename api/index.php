<?php
declare(strict_types=1);

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

if (extension_loaded('zlib') && !headers_sent()) {
    ini_set('zlib.output_compression', '1');
}
Response::securite();
Response::cors($_SERVER['HTTP_ORIGIN'] ?? null);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $req = Request::capture();
    $router = new Router();
    (require BASE_PATH . '/src/routes.php')($router);
    $router->dispatch($req);
} catch (ApiException $e) {
    Response::erreur($e->http, $e->codeErreur, $e->getMessage(), $e->extra);
} catch (\Throwable $e) {
    error_log((string) $e);
    Response::erreur(500, 'erreur_serveur', APP_ENV === 'prod' ? 'Erreur interne.' : $e->getMessage());
}
