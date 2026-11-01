<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public ?array $user = null;
    public array $params = [];
    public string $tokenHash = '';
    private static ?string $ipCourante = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers
    ) {
    }

    public static function capture(): self
    {
        $route = $_GET['route'] ?? null;
        if (!is_string($route) || $route === '') {
            $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $route = preg_match('#/api(?:/index\.php)?(/.*)$#', $uri, $m) ? $m[1] : '/';
        }
        $path = '/' . trim((string) preg_replace('#^/+v1(?=/|$)#', '', '/' . ltrim($route, '/')), '/');

        $headers = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth !== null) {
            $headers['authorization'] = $auth;
        }

        $body = [];
        $raw = (string) file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new ApiException(400, 'json_invalide', 'Corps JSON invalide.');
            }
            $body = $decoded;
        }
        $query = $_GET;
        unset($query['route']);
        self::$ipCourante = $_SERVER['REMOTE_ADDR'] ?? null;
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $query, $body, $headers);
    }

    public static function ip(): ?string
    {
        return self::$ipCourante ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    }

    public function bearer(): ?string
    {
        $h = $this->headers['authorization'] ?? '';
        return preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,})$/', $h, $m) ? $m[1] : null;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->headers['user-agent'] ?? ''), 0, 255);
    }

    public function page(): array
    {
        $page = max(1, (int) ($this->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($this->query['per_page'] ?? 30)));
        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
