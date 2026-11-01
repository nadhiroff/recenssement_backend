<?php
declare(strict_types=1);

namespace App\Services;

interface OtpSenderInterface
{
    public function envoyer(string $destinataire, string $code): bool;
}
