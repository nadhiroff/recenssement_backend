<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(mixed $payload, int $http = 200): void
    {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function ok(mixed $data, int $http = 200): void
    {
        self::json(['ok' => true, 'data' => $data], $http);
    }

    public static function erreur(int $http, string $code, string $message, array $extra = []): void
    {
        self::json(['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra], $http);
    }

    public static function telechargement(string $contenu, string $nom, string $type): void
    {
        http_response_code(200);
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $nom . '"');
        echo $contenu;
    }

    public static function securite(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
    }

    public static function cors(?string $origine): void
    {
        if ($origine !== null && in_array($origine, CORS_ALLOWED_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origine);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }
    }
}
