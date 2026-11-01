<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class NormalisationService
{
    private const TRANSLITTERATION = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y',
        // Lettres du fon et du mina
        'Ɔ' => 'O', 'Ɛ' => 'E', 'Ɖ' => 'D', 'Ƒ' => 'F', 'Ŋ' => 'N', 'Ʋ' => 'V', 'Ɣ' => 'G',
    ];

    /** @var array<string, string>|null */
    private static ?array $variantes = null;

    public static function viderCache(): void
    {
        self::$variantes = null;
    }

    public static function sansAccents(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if ($d !== false) {
                $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
            }
        }
        return strtr($s, self::TRANSLITTERATION);
    }

    /** @param array<string, string>|null $variantes injectable pour les tests unitaires */
    public static function normaliser(string $valeur, ?array $variantes = null): string
    {
        return implode(' ', self::tokens($valeur, $variantes));
    }

    /** @return string[] mots normalisés, variantes remplacées */
    public static function tokens(string $valeur, ?array $variantes = null): array
    {
        $variantes ??= self::variantes();
        $s = self::sansAccents($valeur);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $mots = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(static fn(string $m): string => $variantes[$m] ?? $m, $mots);
    }

    /** @return array<string, string> */
    public static function variantes(): array
    {
        if (self::$variantes === null) {
            self::$variantes = [];
            foreach (Database::all('SELECT variante, forme_canonique FROM variantes_noms WHERE actif = 1') as $v) {
                self::$variantes[$v['variante']] = $v['forme_canonique'];
            }
        }
        return self::$variantes;
    }

    /** Recalcule tous les noms normalisés et mots-clés après une modification des variantes. */
    public static function recalculerTout(): int
    {
        self::viderCache();
        return Database::tx(static function (): int {
            $noms = Database::all('SELECT id, valeur FROM noms_personne');
            $n = 0;
            foreach ($noms as $nom) {
                Database::run('UPDATE noms_personne SET valeur_normalisee = ? WHERE id = ?', [self::normaliser($nom['valeur']), $nom['id']]);
                self::ecrireTokens((int) $nom['id'], $nom['valeur']);
                $n++;
            }
            return $n;
        });
    }

    public static function ecrireTokens(int $nomId, string $valeur): void
    {
        Database::run('DELETE FROM noms_tokens WHERE nom_id = ?', [$nomId]);
        foreach (array_unique(self::tokens($valeur)) as $t) {
            Database::run('INSERT INTO noms_tokens (nom_id, token) VALUES (?, ?)', [$nomId, mb_substr($t, 0, 80)]);
        }
    }
}
