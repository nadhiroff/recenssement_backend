<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    public static function echec(string $champ, string $message): never
    {
        throw new ApiException(422, 'validation', $message, ['champ' => $champ]);
    }

    public static function texte(array $d, string $champ, int $max = 160, bool $requis = true): ?string
    {
        $v = $d[$champ] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($requis) {
                self::echec($champ, "Le champ $champ est obligatoire.");
            }
            return null;
        }
        if (!is_string($v) && !is_int($v)) {
            self::echec($champ, "Le champ $champ est invalide.");
        }
        $v = trim(strip_tags((string) $v));
        if (mb_strlen($v) > $max) {
            self::echec($champ, "Le champ $champ est trop long ($max caractères maximum).");
        }
        return $v;
    }

    public static function entier(array $d, string $champ, bool $requis = true, ?int $min = null, ?int $max = null): ?int
    {
        $v = $d[$champ] ?? null;
        if ($v === null || $v === '') {
            if ($requis) {
                self::echec($champ, "Le champ $champ est obligatoire.");
            }
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            self::echec($champ, "Le champ $champ doit être un nombre entier.");
        }
        $v = (int) $v;
        if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
            self::echec($champ, "Le champ $champ est hors limites.");
        }
        return $v;
    }

    public static function choix(array $d, string $champ, array $valeurs, ?string $defaut = null): string
    {
        $v = $d[$champ] ?? $defaut;
        if (!is_string($v) || !in_array($v, $valeurs, true)) {
            self::echec($champ, "Le champ $champ est invalide.");
        }
        return $v;
    }

    public static function booleen(array $d, string $champ, bool $defaut = false): bool
    {
        return filter_var($d[$champ] ?? $defaut, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Numéro international. Bénin : 10 chiffres commençant par 01 depuis la renumérotation ;
     * un ancien numéro à 8 chiffres reçoit le préfixe 01 pour qu'une personne n'ait qu'un identifiant.
     */
    public static function telephone(array $d, string $champ, bool $requis = true): ?string
    {
        $v = $d[$champ] ?? null;
        if ($v === null || $v === '') {
            if ($requis) {
                self::echec($champ, 'Le numéro est obligatoire.');
            }
            return null;
        }
        $v = (string) preg_replace('/[\s().\-]/', '', (string) $v);
        $v = (string) preg_replace('/^\+229(\d{8})$/', '+22901$1', $v);
        if (str_starts_with($v, '+229') && !preg_match('/^\+22901\d{8}$/', $v)) {
            self::echec($champ, 'Numéro béninois invalide : +22901 suivi de 8 chiffres.');
        }
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $v)) {
            self::echec($champ, 'Numéro invalide : utilisez le format international, par exemple +22901…');
        }
        return $v;
    }

    public static function email(array $d, string $champ, bool $requis = true): ?string
    {
        $v = trim((string) ($d[$champ] ?? ''));
        if ($v === '') {
            if ($requis) {
                self::echec($champ, 'L\'adresse email est obligatoire.');
            }
            return null;
        }
        if (mb_strlen($v) > 190 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            self::echec($champ, 'Adresse email invalide.');
        }
        return mb_strtolower($v);
    }

    public static function annee(array $d, string $champ): ?int
    {
        return self::entier($d, $champ, false, 1700, (int) date('Y'));
    }

    public static function uuid(array $d, string $champ): string
    {
        $v = (string) ($d[$champ] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            self::echec($champ, 'Identifiant client invalide.');
        }
        return strtolower($v);
    }
}
