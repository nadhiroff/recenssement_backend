<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{string, string, callable, bool}> */
    private array $routes = [];

    public function get(string $p, callable $h, bool $auth = true): void
    {
        $this->routes[] = ['GET', $p, $h, $auth];
    }

    public function post(string $p, callable $h, bool $auth = true): void
    {
        $this->routes[] = ['POST', $p, $h, $auth];
    }

    public function patch(string $p, callable $h, bool $auth = true): void
    {
        $this->routes[] = ['PATCH', $p, $h, $auth];
    }

    public function delete(string $p, callable $h, bool $auth = true): void
    {
        $this->routes[] = ['DELETE', $p, $h, $auth];
    }

    /** @return array<int, array{string, string, callable, bool}> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $req): void
    {
        $methodeAutreTrouvee = false;
        foreach ($this->routes as [$method, $pattern, $handler, $auth]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            if ($method !== $req->method) {
                $methodeAutreTrouvee = true;
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($auth) {
                Auth::exiger($req);
            } else {
                Auth::tenter($req);
            }
            $handler($req);
            return;
        }
        throw $methodeAutreTrouvee
            ? new ApiException(405, 'methode_non_autorisee', 'Méthode non autorisée.')
            : new ApiException(404, 'route_inconnue', 'Route inconnue.');
    }
}
