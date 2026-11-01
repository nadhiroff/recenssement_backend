<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ParametreService
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function tous(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT cle, valeur FROM parametres') as $p) {
                self::$cache[$p['cle']] = $p['valeur'];
            }
        }
        return self::$cache;
    }

    public static function entier(string $cle, int $defaut = 0): int
    {
        return (int) (self::tous()[$cle] ?? $defaut);
    }

    public static function viderCache(): void
    {
        self::$cache = null;
    }
}
