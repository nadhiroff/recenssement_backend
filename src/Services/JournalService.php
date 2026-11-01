<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

final class JournalService
{
    public static function ecrire(?int $utilisateurId, string $action, ?string $type = null, ?int $id = null, ?int $brancheId = null, array $details = []): void
    {
        Database::insert('journal_actions', [
            'utilisateur_id' => $utilisateurId,
            'action' => $action,
            'entite_type' => $type,
            'entite_id' => $id,
            'branche_id' => $brancheId,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip' => Request::ip(),
        ]);
    }
}
