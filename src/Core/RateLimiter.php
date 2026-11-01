<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /** @param array{0:int,1:int} $limite [requêtes, fenêtre en secondes] */
    public static function verifier(string $portee, string $identifiant, array $limite): void
    {
        [$max, $fenetre] = $limite;
        $cle = hash('sha256', $portee . '|' . $identifiant);
        $maintenant = time();
        Database::run(
            'INSERT INTO rate_limits (cle, fenetre_debut, compteur) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
               compteur = IF(fenetre_debut + ? <= ?, 1, compteur + 1),
               fenetre_debut = IF(fenetre_debut + ? <= ?, VALUES(fenetre_debut), fenetre_debut)',
            [$cle, $maintenant, $fenetre, $maintenant, $fenetre, $maintenant]
        );
        $ligne = Database::one('SELECT fenetre_debut, compteur FROM rate_limits WHERE cle = ?', [$cle]);
        if ($ligne !== null && (int) $ligne['compteur'] > $max) {
            $attente = max(1, (int) $ligne['fenetre_debut'] + $fenetre - $maintenant);
            header('Retry-After: ' . $attente);
            throw new ApiException(429, 'trop_de_requetes', 'Trop de tentatives. Réessayez plus tard.', ['retry_after' => $attente]);
        }
    }
}
