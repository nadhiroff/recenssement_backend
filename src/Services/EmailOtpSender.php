<?php
declare(strict_types=1);

namespace App\Services;

final class EmailOtpSender implements OtpSenderInterface
{
    public function envoyer(string $destinataire, string $code): bool
    {
        $minutes = (int) ceil(OTP_TTL_SECONDS / 60);
        $corps = '<p>Voici votre code de connexion :</p>'
            . '<p style="font-size:32px;letter-spacing:8px;font-weight:bold;color:#C2662D;margin:16px 0">' . $code . '</p>'
            . "<p>Ce code expire dans $minutes minutes. Ne le communiquez à personne.</p>";
        return MailService::envoyer($destinataire, 'Votre code de connexion', 'Code de connexion', $corps);
    }
}
