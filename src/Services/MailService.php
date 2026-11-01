<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    public static function envoyer(string $destinataire, string $sujet, string $titre, string $corpsHtml): bool
    {
        $html = self::gabarit($titre, $corpsHtml);
        // Les adresses fictives du jeu de démonstration ne partent jamais.
        if (MAIL_DRIVER === 'log' || str_ends_with($destinataire, '.test')) {
            self::tracer($destinataire, $sujet);
            return true;
        }
        try {
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->Host = MAIL_HOST;
            $m->Port = MAIL_PORT;
            $m->SMTPAuth = true;
            $m->Username = MAIL_USERNAME;
            $m->Password = MAIL_PASSWORD;
            $m->SMTPSecure = MAIL_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $m->Timeout = 15;
            $m->CharSet = 'UTF-8';
            $m->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $m->addAddress($destinataire);
            $m->isHTML(true);
            $m->Subject = $sujet;
            $m->Body = $html;
            $m->AltBody = trim(html_entity_decode(strip_tags($corpsHtml), ENT_QUOTES, 'UTF-8'));
            $m->send();
            return true;
        } catch (\Throwable $e) {
            error_log('Envoi email échoué : ' . $e->getMessage());
            return false;
        }
    }

    private static function tracer(string $destinataire, string $sujet): void
    {
        $masque = substr(hash('sha256', $destinataire), 0, 8);
        file_put_contents(BASE_PATH . '/storage/logs/mail.log', gmdate('c') . " [$masque] $sujet\n", FILE_APPEND);
    }

    private static function gabarit(string $titre, string $corps): string
    {
        $nom = htmlspecialchars(APP_NAME, ENT_QUOTES);
        $t = htmlspecialchars($titre, ENT_QUOTES);
        return <<<HTML
<!doctype html><html lang="fr"><body style="margin:0;background:#FAF6EF;font-family:Arial,sans-serif;color:#1E2420">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" style="max-width:480px;background:#fff;border-radius:16px;overflow:hidden">
<tr><td style="background:#1F6B4F;color:#fff;padding:18px 24px;font-size:18px;font-weight:bold">$nom</td></tr>
<tr><td style="padding:24px;font-size:16px;line-height:1.5"><h1 style="font-size:20px;margin:0 0 12px;color:#1F6B4F">$t</h1>$corps</td></tr>
<tr><td style="padding:14px 24px;font-size:12px;color:#6B746E;border-top:1px solid #eee">Famille ZOCLI de Sahouè Doutou</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
